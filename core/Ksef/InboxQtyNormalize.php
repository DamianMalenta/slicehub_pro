<?php

declare(strict_types=1);

namespace SliceHub\Ksef;

require_once __DIR__ . '/../InvoiceLineQtyNormalizer.php';

/**
 * Odśwież cache normalizacji linii faktury + budowa payloadu PZ.
 */
final class InboxQtyNormalize
{
    public static function refreshInvoiceLines(
        \PDO $pdo,
        int $tenantId,
        int $invoiceId,
        string $supplierNip = '',
        bool $inventoryOnly = true
    ): void {
        $invOnly = '';
        if ($inventoryOnly && self::hasLineType($pdo)) {
            $invOnly = " AND COALESCE(line_type, 'INVENTORY') = 'INVENTORY' ";
        }

        $st = $pdo->prepare(
            "SELECT l.id, l.external_name, l.unit, l.qty, l.unit_net, l.line_net_minor, l.resolved_sku
               FROM sh_ksef_invoice_lines l
              WHERE l.ksef_invoice_id = :iid {$invOnly}
                AND l.resolved_sku IS NOT NULL AND TRIM(l.resolved_sku) <> ''
              ORDER BY l.line_no"
        );
        $st->execute([':iid' => $invoiceId]);
        $lines = $st->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $hasCols = \InvoiceLineQtyNormalizer::lineColumnsExist($pdo);
        $upd = $hasCols ? $pdo->prepare(
            "UPDATE sh_ksef_invoice_lines
                SET qty_normalized = :qn,
                    unit_net_normalized = :un,
                    normalization_status = :st,
                    normalization_meta = :meta
              WHERE id = :id"
        ) : null;

        foreach ($lines as $line) {
            $norm = self::normalizeLine($pdo, $tenantId, $line, $supplierNip);
            if ($upd !== null) {
                $meta = is_array($norm['normalization_meta'] ?? null) ? $norm['normalization_meta'] : [];
                if (!empty($norm['message'])) {
                    $meta['message'] = (string) $norm['message'];
                }
                $upd->execute([
                    ':qn'   => $norm['qty_normalized'] > 0 ? $norm['qty_normalized'] : null,
                    ':un'   => $norm['unit_net_normalized'] > 0 ? $norm['unit_net_normalized'] : null,
                    ':st'   => $norm['status'],
                    ':meta' => json_encode($meta, JSON_UNESCAPED_UNICODE),
                    ':id'   => $line['id'],
                ]);
            }
        }
    }

    /**
     * @param array<string,mixed> $line
     * @return array<string,mixed>
     */
    public static function normalizeLine(\PDO $pdo, int $tenantId, array $line, string $supplierNip = ''): array
    {
        $sku = trim((string) ($line['resolved_sku'] ?? ''));
        if ($sku === '') {
            return [
                'status' => \InvoiceLineQtyNormalizer::STATUS_BLOCKED,
                'qty_normalized' => 0,
                'unit_net_normalized' => 0,
                'normalization_meta' => [],
                'message' => 'Brak SKU.',
                'is_blocked' => true,
            ];
        }

        $st = $pdo->prepare(
            "SELECT sku, base_unit FROM sys_items
              WHERE tenant_id = :tid AND sku = :sku AND is_deleted = 0 AND is_active = 1 LIMIT 1"
        );
        $st->execute([':tid' => $tenantId, ':sku' => $sku]);
        $item = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$item) {
            return [
                'status' => \InvoiceLineQtyNormalizer::STATUS_BLOCKED,
                'qty_normalized' => 0,
                'unit_net_normalized' => 0,
                'normalization_meta' => [],
                'message' => "SKU '{$sku}' nie istnieje.",
                'is_blocked' => true,
            ];
        }

        $pack = \InvoiceLineQtyNormalizer::loadPackMapping(
            $pdo,
            $tenantId,
            (string) ($line['external_name'] ?? ''),
            $supplierNip
        );

        return \InvoiceLineQtyNormalizer::normalize($line, $item, $pack);
    }

    /**
     * @param array<string,mixed> $line
     * @return array{quantity: float, unit_net_cost: float, normalization: array}
     */
    public static function resolvePzLine(\PDO $pdo, int $tenantId, array $line, string $supplierNip): array
    {
        $norm = self::normalizeLine($pdo, $tenantId, $line, $supplierNip);
        if (!empty($norm['is_blocked']) || ($norm['status'] ?? '') === \InvoiceLineQtyNormalizer::STATUS_BLOCKED) {
            $msg = (string) ($norm['message'] ?? 'Normalizacja zablokowana.');
            throw new \InvalidArgumentException($msg);
        }

        return [
            'quantity'      => (float) $norm['qty_normalized'],
            'unit_net_cost' => (float) $norm['unit_net_normalized'],
            'normalization' => $norm,
        ];
    }

    private static function hasLineType(\PDO $pdo): bool
    {
        static $cache = null;
        if ($cache !== null) {
            return $cache;
        }
        try {
            $db = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tn AND COLUMN_NAME = :cn'
            );
            $st->execute([':db' => $db, ':tn' => 'sh_ksef_invoice_lines', ':cn' => 'line_type']);
            $cache = ((int) $st->fetchColumn()) > 0;
        } catch (\Throwable $e) {
            $cache = false;
        }

        return $cache;
    }
}
