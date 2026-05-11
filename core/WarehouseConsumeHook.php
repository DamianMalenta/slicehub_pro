<?php

declare(strict_types=1);

require_once __DIR__ . '/WzEngine.php';

/**
 * SliceHub — Warehouse Consume Hook
 *
 * Centralny helper konsumpcji magazynu po `transitionOrder('accepted')`.
 * Wywoływany SYNCHRONICZNIE z `api/pos/engine.php#accept_order` oraz
 * `api/orders/accept.php` PO commit-cie outer transakcji.
 *
 * Architektura (Konstytucja v5 § Prawo II — Bliźniak Cyfrowy):
 *   POS klika "PRZYGOTUJ" → tranzycja `new → accepted` w OSM →
 *   COMMIT outer transakcji → Hook woła WzEngine::consumeForOrder →
 *   wh_stock spada o `Σ(recipe_qty × (1 + waste/100) × multiplier)` →
 *   wh_documents (typ WZ) zapisany → wh_stock_logs audit trail.
 *
 * Dlaczego PO commit-cie outer:
 *   1. WzEngine::consumeForOrder ma własną `pdo->beginTransaction()`.
 *      Nested transaction w PDO MySQL = brak prawdziwej izolacji
 *      (savepoint-y wymagają jawnej obsługi). Czystsze: outer się commit-uje,
 *      hook robi własną, niezależną transakcję.
 *   2. Failure hook NIE blokuje akceptu zamówienia.
 *      Klient może mieć zamówienie BEZ pełnej receptury (np. promo bez
 *      składników). To NIE jest błąd accept. Loggujemy alert.
 *      Manager naprawia ręczną korektą (KOR engine).
 *   3. Failure hook NIE rollbackuje stworzonych ticketów KDS.
 *      Zamówienie zostało zaakceptowane semantycznie — kuchnia gotuje.
 *
 * Resolution warehouse_id (zgodne z wzorcem `api/online/engine.php:1101`):
 *   1. Z payload (jeśli caller przekazał).
 *   2. Z `sh_tenant_settings.orders_default_warehouse_id`.
 *   3. Fallback `'MAIN'`.
 *
 * Konstytucja v5 § Prawo VI Snajper:
 *   - Każde query z `tenant_id = :tid`.
 *   - Cross-silo wyłącznie przez SKU (klucze znakowe), nie po ID.
 *   - Hook nie modyfikuje istniejącej logiki — tylko dorzuca jeden side-effect.
 *
 * Konstytucja v5 § Prawo VIII Domknięcie Kontraktu:
 *   - Ten plik UNCANCELS adnotację @planned na WzEngine::consumeForOrder.
 *   - Funkcja ma teraz call-site (ten plik) + test E2E w `_docs/sessions/`.
 */
class WarehouseConsumeHook
{
    /**
     * Wywołanie hook-a po pomyślnej tranzycji `→ accepted`.
     *
     * @param PDO         $pdo
     * @param int         $tenantId
     * @param string      $orderId        UUID CHAR(36)
     * @param int         $userId         Aktor który zaakceptował
     * @param string|null $warehouseId    Opcjonalnie. Gdy null → auto-resolve.
     *
     * @return array{success:bool, doc_id?:int, doc_number?:string, total_cost?:float, deductions?:array<string,float>, warehouse_id:string, error?:string, skipped?:bool}
     *   - success=true z doc_id → konsumpcja zapisana, magazyn pomniejszony
     *   - success=false z skipped=true → brak receptury (no error, info only)
     *   - success=false z error → realny błąd (zalogowane, kuchnia gotuje dalej)
     */
    public static function onOrderAccepted(
        PDO $pdo,
        int $tenantId,
        string $orderId,
        int $userId,
        ?string $warehouseId = null
    ): array {
        $resolvedWarehouseId = $warehouseId ?: self::resolveDefaultWarehouseId($pdo, $tenantId);

        try {
            $result = WzEngine::consumeForOrder(
                $pdo,
                $tenantId,
                $resolvedWarehouseId,
                $orderId,
                $userId
            );

            if (!is_array($result)) {
                error_log("[WarehouseConsumeHook] WzEngine returned non-array for order {$orderId}");
                return [
                    'success'      => false,
                    'warehouse_id' => $resolvedWarehouseId,
                    'error'        => 'WzEngine returned invalid response.',
                ];
            }

            // Brak dedukcji = brak receptury → info, nie błąd
            $errorMsg = (string) ($result['error'] ?? '');
            $isNoRecipe = str_contains($errorMsg, 'recipes may not be configured')
                       || str_contains($errorMsg, 'no line items');

            if (!($result['success'] ?? false) && $isNoRecipe) {
                return [
                    'success'      => false,
                    'skipped'      => true,
                    'warehouse_id' => $resolvedWarehouseId,
                    'error'        => $errorMsg,
                ];
            }

            $result['warehouse_id'] = $resolvedWarehouseId;
            return $result;
        } catch (\Throwable $e) {
            error_log(sprintf(
                '[WarehouseConsumeHook] consume failed for order %s tenant %d: %s',
                $orderId,
                $tenantId,
                $e->getMessage()
            ));
            return [
                'success'      => false,
                'warehouse_id' => $resolvedWarehouseId,
                'error'        => 'Hook threw: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Resolve domyślnego warehouse_id dla tenanta.
     *
     * Bariera tenant_id zgodna z Konstytucją v5 § Prawo VI.
     */
    private static function resolveDefaultWarehouseId(PDO $pdo, int $tenantId): string
    {
        try {
            $stmt = $pdo->prepare(
                "SELECT setting_value
                   FROM sh_tenant_settings
                  WHERE tenant_id = :tid
                    AND setting_key = 'orders_default_warehouse_id'
                  LIMIT 1"
            );
            $stmt->execute([':tid' => $tenantId]);
            $value = $stmt->fetchColumn();
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        } catch (\Throwable $e) {
            error_log('[WarehouseConsumeHook.resolveDefaultWarehouseId] ' . $e->getMessage());
        }
        return 'MAIN';
    }
}
