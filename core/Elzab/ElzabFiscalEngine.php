<?php

declare(strict_types=1);

namespace SliceHub\Elzab;

/**
 * ElzabFiscalEngine — synchroniczna fiskalizacja zamówień przez drukarkę Elzab Zeta Online.
 *
 * Nie używa asynchronicznego IntegrationDispatcher (outbox + cURL) ponieważ:
 *   1. Fiskalizacja jest SYNCHRONICZNA — POS musi natychmiast wiedzieć czy paragon się wydrukował
 *   2. Drukarka używa surowego TCP (Thermal), nie HTTP — cURL nie pasuje
 *   3. Numer paragonu fiskalnego musi wrócić do POS w tej samej odpowiedzi HTTP
 *
 * Flow:
 *   POS → POST /api/pos/engine.php?action=fiscal_print&order_id=X
 *     → ElzabFiscalEngine::fiscalizeOrder($pdo, $orderId, $tenantId)
 *       → Pobiera zamówienie + linie z DB
 *       → Mapuje na komendy Thermal
 *       → ElzabPrinter::connect() → printReceipt() → disconnect()
 *       → Zapisuje fiscal_receipt_number do sh_orders
 *     → Zwraca {success: true, fiscal_receipt_number: "000123"}
 *
 * Konfiguracja drukarki w sh_tenant_integrations:
 *   provider = 'elzab'
 *   api_base_url = 'tcp://192.168.1.50:1001'
 *   credentials = {"cashbox": "POS1", "cashier_field": "username"}
 *   is_active = 1
 *
 * Lub w sh_tenant_settings:
 *   ELZAB_PRINTER_HOST = '192.168.1.50'
 *   ELZAB_PRINTER_PORT = '1001'
 */
final class ElzabFiscalEngine
{
    /**
     * Fiskalizuj zamówienie — wydrukuj paragon fiskalny i zapisz numer.
     *
     * @param \PDO  $pdo
     * @param string $orderId   UUID zamówienia
     * @param int    $tenantId  ID tenanta
     * @param int    $userId    ID użytkownika (kasjera)
     * @param bool   $force     Wymuś ponowną fiskalizację (reprint)
     * @return array{success: bool, fiscal_receipt_number?: string, error?: string}
     */
    public static function fiscalizeOrder(\PDO $pdo, string $orderId, int $tenantId, int $userId, bool $force = false): array
    {
        // 1. Pobierz konfigurację drukarki
        $config = self::resolvePrinterConfig($pdo, $tenantId);
        if ($config === null) {
            return ['success' => false, 'error' => 'Drukarka fiskalna nie jest skonfigurowana dla tego tenanta'];
        }

        // 2. Pobierz zamówienie
        $order = self::fetchOrder($pdo, $orderId, $tenantId);
        if ($order === null) {
            return ['success' => false, 'error' => 'Zamówienie nie znalezione'];
        }

        // 2a. Guard — nie fiskalizuj ponownie jeśli paragon już wydrukowany
        if (!$force && !empty($order['fiscal_receipt_number'])) {
            return ['success' => false, 'error' => 'Zamówienie już ma paragon fiskalny nr ' . $order['fiscal_receipt_number']];
        }

        // 3. Pobierz linie zamówienia
        $lines = self::fetchOrderLines($pdo, $orderId, $tenantId);
        if (empty($lines)) {
            return ['success' => false, 'error' => 'Zamówienie nie ma linii'];
        }

        // 4. Pobierz płatności
        $payments = self::fetchPayments($pdo, $orderId, $tenantId);

        // 5. Pobierz imię kasjera
        $cashier = self::fetchCashierName($pdo, $userId) ?: 'POS';

        // 6. Mapuj linie na format Thermal
        $thermalLines = self::mapOrderLines($lines);

        // 6a. Dodaj opłatę za dostawę jako osobną linię (jeśli > 0)
        $deliveryFee = (int)($order['delivery_fee'] ?? 0);
        if ($deliveryFee > 0) {
            $orderType = (string)($order['order_type'] ?? 'delivery');
            $deliveryVatRate = ($orderType === 'dine_in') ? 8.0 : 5.0;
            $thermalLines[] = [
                'name' => 'Dostawa',
                'quantity' => 1,
                'unit_price' => $deliveryFee / 100,
                'vat_rate' => $deliveryVatRate,
                'line_total' => $deliveryFee / 100,
            ];
        }

        $thermalPayments = self::mapPayments($payments, $order);
        $total = self::orderTotalGrosze($order, $lines);

        // 6b. Rabat — kwotowy, stosowany globalnie w cmdTransactionEnd
        $discountAmount = (int)($order['discount_amount'] ?? 0);
        $discount = $discountAmount > 0 ? $discountAmount / 100 : 0.0;

        // 7. Pobierz stopkę paragonu z konfiguracji
        $footer = self::resolveFooter($pdo, $tenantId, $config);

        // 8. Połącz i wydrukuj
        $printer = new ElzabPrinter($config['host'], $config['port'], $config['connect_timeout'], $config['read_timeout']);

        try {
            $printer->connect();
            $receiptNumber = $printer->printReceipt(
                $thermalLines,
                $total,
                $thermalPayments,
                $config['cashbox'],
                $cashier,
                (string)($order['order_number'] ?? $orderId),
                $discount,
                $footer
            );
            $printer->disconnect();
        } catch (ElzabPrinterException $e) {
            // Anuluj transakcję jeśli w trakcie
            try { $printer->disconnect(); } catch (\Throwable $e2) {}
            return ['success' => false, 'error' => $e->getMessage()];
        }

        // 9. Zapisz numer paragonu do DB
        if ($receiptNumber !== '') {
            self::saveFiscalReceiptNumber($pdo, $orderId, $tenantId, $receiptNumber);
        }

        return ['success' => true, 'fiscal_receipt_number' => $receiptNumber];
    }

    /**
     * Wydrukuj raport dobowy (zamknij dobę fiskalną).
     */
    public static function printDailyReport(\PDO $pdo, int $tenantId): array
    {
        $config = self::resolvePrinterConfig($pdo, $tenantId);
        if ($config === null) {
            return ['success' => false, 'error' => 'Drukarka fiskalna nie jest skonfigurowana'];
        }

        $printer = new ElzabPrinter($config['host'], $config['port'], $config['connect_timeout'], $config['read_timeout']);

        try {
            $printer->connect();
            $printer->printDailyReport();
            $printer->disconnect();
        } catch (ElzabPrinterException $e) {
            try { $printer->disconnect(); } catch (\Throwable $e2) {}
            return ['success' => false, 'error' => $e->getMessage()];
        }

        return ['success' => true];
    }

    /**
     * Sprawdź status drukarki (ping).
     */
    public static function checkStatus(\PDO $pdo, int $tenantId): array
    {
        $config = self::resolvePrinterConfig($pdo, $tenantId);
        if ($config === null) {
            return ['success' => false, 'error' => 'Drukarka nie skonfigurowana'];
        }

        $printer = new ElzabPrinter($config['host'], $config['port'], 3, 5);
        if (!$printer->ping()) {
            return ['success' => false, 'error' => "Brak połączenia z drukarką {$config['host']}:{$config['port']}"];
        }

        return ['success' => true, 'host' => $config['host'], 'port' => $config['port']];
    }

    // ── Konfiguracja ──────────────────────────────────────────────────────

    /**
     * Pobierz konfigurację drukarki z sh_tenant_integrations lub sh_tenant_settings.
     *
     * @return array{host: string, port: int, cashbox: string, connect_timeout: int, read_timeout: int}|null
     */
    private static function resolvePrinterConfig(\PDO $pdo, int $tenantId): ?array
    {
        $host = null;
        $port = 1001;
        $cashbox = 'POS1';

        // Spróbuj sh_tenant_integrations (provider='elzab')
        try {
            $stmt = $pdo->prepare(
                "SELECT api_base_url, credentials
                 FROM sh_tenant_integrations
                 WHERE tenant_id = :tid AND provider = 'elzab' AND is_active = 1
                 LIMIT 1"
            );
            $stmt->execute([':tid' => $tenantId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if ($row) {
                $baseUrl = (string)($row['api_base_url'] ?? '');
                // Format: tcp://192.168.1.50:1001
                if (preg_match('#tcp://([^:]+):(\d+)#', $baseUrl, $m)) {
                    $host = $m[1];
                    $port = (int)$m[2];
                }

                $creds = json_decode((string)($row['credentials'] ?? '{}'), true);
                if (is_array($creds)) {
                    $cashbox = $creds['cashbox'] ?? $cashbox;
                }
            }
        } catch (\Throwable $e) {
            // Tabela może nie istnieć
        }

        // Fallback: sh_tenant_settings
        if ($host === null) {
            try {
                $stmt = $pdo->prepare(
                    "SELECT setting_value FROM sh_tenant_settings
                     WHERE tenant_id = :tid AND setting_key = 'ELZAB_PRINTER_HOST' LIMIT 1"
                );
                $stmt->execute([':tid' => $tenantId]);
                $host = $stmt->fetchColumn() ?: null;

                if ($host) {
                    $stmt = $pdo->prepare(
                        "SELECT setting_value FROM sh_tenant_settings
                         WHERE tenant_id = :tid AND setting_key = 'ELZAB_PRINTER_PORT' LIMIT 1"
                    );
                    $stmt->execute([':tid' => $tenantId]);
                    $portVal = $stmt->fetchColumn();
                    if ($portVal) $port = (int)$portVal;
                }
            } catch (\Throwable $e) {
                // Settings mogą nie istnieć
            }
        }

        if ($host === null) {
            return null;
        }

        return [
            'host' => $host,
            'port' => $port,
            'cashbox' => $cashbox,
            'connect_timeout' => 5,
            'read_timeout' => 15,
        ];
    }

    /**
     * Pobierz stopkę paragonu z konfiguracji.
     * Kolejność: sh_tenant_integrations credentials.footer_line_N → sh_tenant_settings ELZAB_FOOTER_LINE_N → defaults.
     *
     * @return array{line1: string, line2: string, line3: string}
     */
    private static function resolveFooter(\PDO $pdo, int $tenantId, array $config): array
    {
        $line1 = '';
        $line2 = '';
        $line3 = '';

        // Spróbuj z sh_tenant_settings
        try {
            $stmt = $pdo->prepare(
                "SELECT setting_key, setting_value FROM sh_tenant_settings
                 WHERE tenant_id = :tid AND setting_key IN
                   ('ELZAB_FOOTER_LINE_1', 'ELZAB_FOOTER_LINE_2', 'ELZAB_FOOTER_LINE_3')"
            );
            $stmt->execute([':tid' => $tenantId]);
            while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
                $key = (string)($row['setting_key'] ?? '');
                $val = (string)($row['setting_value'] ?? '');
                if ($key === 'ELZAB_FOOTER_LINE_1') $line1 = $val;
                if ($key === 'ELZAB_FOOTER_LINE_2') $line2 = $val;
                if ($key === 'ELZAB_FOOTER_LINE_3') $line3 = $val;
            }
        } catch (\Throwable $e) {
            // Settings mogą nie istnieć
        }

        // Defaults jeśli puste
        if ($line1 === '' && $line2 === '' && $line3 === '') {
            $line1 = 'Dziekujemy!';
            $line2 = '';
            $line3 = '';
        }

        return ['line1' => $line1, 'line2' => $line2, 'line3' => $line3];
    }

    /**
     * Zapisz konfigurację drukarki (host, port, cashbox, stopka) do sh_tenant_settings.
     */
    public static function saveConfig(\PDO $pdo, int $tenantId, array $config): void
    {
        $settings = [
            'ELZAB_PRINTER_HOST' => (string)($config['host'] ?? ''),
            'ELZAB_PRINTER_PORT' => (string)($config['port'] ?? '1001'),
            'ELZAB_FOOTER_LINE_1' => (string)($config['footer_line_1'] ?? ''),
            'ELZAB_FOOTER_LINE_2' => (string)($config['footer_line_2'] ?? ''),
            'ELZAB_FOOTER_LINE_3' => (string)($config['footer_line_3'] ?? ''),
        ];

        $stmt = $pdo->prepare(
            "INSERT INTO sh_tenant_settings (tenant_id, setting_key, setting_value)
             VALUES (:tid, :key, :val)
             ON DUPLICATE KEY UPDATE setting_value = :val2"
        );

        foreach ($settings as $key => $val) {
            if ($val === '') continue;
            $stmt->execute([':tid' => $tenantId, ':key' => $key, ':val' => $val, ':val2' => $val]);
        }

        // Cashbox do osobnego klucza
        $cashbox = (string)($config['cashbox'] ?? '');
        if ($cashbox !== '') {
            $stmt->execute([':tid' => $tenantId, ':key' => 'ELZAB_CASHBOX', ':val' => $cashbox, ':val2' => $cashbox]);
        }
    }

    /**
     * Pobierz pełną konfigurację drukarki (do UI).
     */
    public static function getConfig(\PDO $pdo, int $tenantId): array
    {
        $config = self::resolvePrinterConfig($pdo, $tenantId);
        if ($config === null) {
            return ['success' => false, 'error' => 'Drukarka nie skonfigurowana'];
        }

        $footer = self::resolveFooter($pdo, $tenantId, $config);

        return [
            'success' => true,
            'host' => $config['host'],
            'port' => $config['port'],
            'cashbox' => $config['cashbox'],
            'footer_line_1' => $footer['line1'],
            'footer_line_2' => $footer['line2'],
            'footer_line_3' => $footer['line3'],
        ];
    }

    // ── Pobieranie danych ─────────────────────────────────────────────────

    private static function fetchOrder(\PDO $pdo, string $orderId, int $tenantId): ?array
    {
        $stmt = $pdo->prepare(
            "SELECT id, order_number, grand_total, subtotal, discount_amount,
                    delivery_fee, tip_amount, payment_method, channel,
                    customer_name, status, order_type, fiscal_receipt_number
             FROM sh_orders
             WHERE id = :oid AND tenant_id = :tid"
        );
        $stmt->execute([':oid' => $orderId, ':tid' => $tenantId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    private static function fetchOrderLines(\PDO $pdo, string $orderId, int $tenantId): array
    {
        $stmt = $pdo->prepare(
            "SELECT id, snapshot_name, quantity, unit_price,
                    line_total, vat_rate, item_sku, comment
             FROM sh_order_lines
             WHERE order_id = :oid
             ORDER BY id ASC"
        );
        $stmt->execute([':oid' => $orderId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private static function fetchPayments(\PDO $pdo, string $orderId, int $tenantId): array
    {
        $stmt = $pdo->prepare(
            "SELECT method, amount_grosze, tendered_grosze
             FROM sh_order_payments
             WHERE order_id = :oid AND tenant_id = :tid
             ORDER BY id ASC"
        );
        $stmt->execute([':oid' => $orderId, ':tid' => $tenantId]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];
    }

    private static function fetchCashierName(\PDO $pdo, int $userId): string
    {
        $stmt = $pdo->prepare("SELECT name FROM sh_users WHERE id = :uid");
        $stmt->execute([':uid' => $userId]);
        return (string)($stmt->fetchColumn() ?: '');
    }

    // ── Mapowanie ──────────────────────────────────────────────────────────

    /**
     * Mapuj linie zamówienia z DB na format Thermal.
     */
    private static function mapOrderLines(array $lines): array
    {
        $result = [];
        foreach ($lines as $line) {
            $name = (string)($line['snapshot_name'] ?? 'Towar');
            $qty = (int)($line['quantity'] ?? 1);
            $unitPrice = (int)($line['unit_price'] ?? 0) / 100;
            $lineTotal = (int)($line['line_total'] ?? 0) / 100;
            $vatRate = (float)($line['vat_rate'] ?? 0);

            $result[] = [
                'name' => $name,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'vat_rate' => $vatRate,
                'line_total' => $lineTotal,
            ];
        }
        return $result;
    }

    /**
     * Mapuj płatności z DB na format Thermal.
     */
    private static function mapPayments(array $payments, array $order): array
    {
        if (empty($payments)) {
            // Fallback: pojedyncza płatność z order.payment_method
            $method = (string)($order['payment_method'] ?? 'cash');
            $total = (int)($order['grand_total'] ?? 0) / 100;
            return [['method' => $method, 'amount' => $total]];
        }

        $result = [];
        foreach ($payments as $pay) {
            $method = (string)($pay['method'] ?? 'cash');
            $amount = (int)($pay['amount_grosze'] ?? 0) / 100;
            $result[] = ['method' => $method, 'amount' => $amount];
        }

        // Napiwek jako osobna płatność gotówką
        $tip = (int)($order['tip_amount'] ?? 0);
        if ($tip > 0) {
            $result[] = ['method' => 'cash', 'amount' => $tip / 100, 'name' => 'Napiwek'];
        }
        return $result;
    }

    /**
     * Oblicz całkowitą kwotę brutto.
     */
    private static function orderTotalGrosze(array $order, array $lines): float
    {
        $total = (int)($order['grand_total'] ?? 0);
        if ($total > 0) return $total / 100;

        // Fallback: suma linii
        $sum = 0;
        foreach ($lines as $line) {
            $sum += (int)($line['line_total'] ?? 0);
        }
        return $sum / 100;
    }

    // ── Zapis ─────────────────────────────────────────────────────────────

    /**
     * Zapisz numer paragonu fiskalnego do sh_orders.
     */
    private static function saveFiscalReceiptNumber(\PDO $pdo, string $orderId, int $tenantId, string $receiptNumber): void
    {
        $stmt = $pdo->prepare(
            "UPDATE sh_orders
             SET fiscal_receipt_number = :num,
                 receipt_printed = 1,
                 updated_at = NOW()
             WHERE id = :oid AND tenant_id = :tid"
        );
        $stmt->execute([
            ':num' => $receiptNumber,
            ':oid' => $orderId,
            ':tid' => $tenantId,
        ]);
    }
}
