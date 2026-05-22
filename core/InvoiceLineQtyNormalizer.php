<?php

declare(strict_types=1);

require_once __DIR__ . '/Units.php';
require_once __DIR__ . '/PackSizeExtractor.php';

/**
 * Normalizacja ilości i ceny jednostkowej z linii KSeF do sys_items.base_unit.
 */
final class InvoiceLineQtyNormalizer
{
    public const STATUS_OK      = 'ok';
    public const STATUS_WARN    = 'warn';
    public const STATUS_BLOCKED = 'blocked';

    /**
     * @param array<string,mixed> $line  qty, unit, unit_net, line_net_minor, external_name
     * @param array<string,mixed> $sysItem  base_unit (required)
     * @param array<string,mixed>|null $packMapping  pack_qty_base?, pack_invoice_unit?
     */
    public static function normalize(array $line, array $sysItem, ?array $packMapping = null): array
    {
        $qtyInvoice = self::toFloat($line['qty'] ?? 0);
        $unitInvoice = trim((string) ($line['unit'] ?? ''));
        $unitNetInvoice = self::toFloat($line['unit_net'] ?? 0);
        $lineNetMinor = (int) ($line['line_net_minor'] ?? 0);
        $lineNet = $lineNetMinor > 0 ? $lineNetMinor / 100.0 : ($qtyInvoice * $unitNetInvoice);
        $externalName = trim((string) ($line['external_name'] ?? ''));
        $externalDesc = trim((string) ($line['external_description'] ?? ''));
        $baseUnit = Units::normalizeLabel((string) ($sysItem['base_unit'] ?? 'kg'));
        if ($baseUnit === '') {
            $baseUnit = 'kg';
        }

        $meta = [
            'qty_invoice'       => $qtyInvoice,
            'unit_invoice'      => $unitInvoice,
            'unit_net_invoice'  => $unitNetInvoice,
            'line_net'          => round($lineNet, 2),
            'base_unit'         => $baseUnit,
            'steps'             => [],
        ];

        if ($qtyInvoice <= 0) {
            return self::result(self::STATUS_BLOCKED, 0, 0, $meta, 'Ilość z faktury musi być > 0.');
        }

        $qtyBase = null;
        $source = '';

        // 1) Zapisany mapping opakowania (learn)
        if ($packMapping !== null) {
            $packBase = self::toFloat($packMapping['pack_qty_base'] ?? 0);
            $packInvUnit = trim((string) ($packMapping['pack_invoice_unit'] ?? ''));
            if ($packBase > 0 && ($packInvUnit === '' || Units::normalizeLabel($packInvUnit) === Units::normalizeLabel($unitInvoice))) {
                $qtyBase = $qtyInvoice * $packBase;
                $source = 'mapping_pack';
                $meta['steps'][] = ['mapping_pack', $packBase];
            }
        }

        // 2) Bezpośrednia konwersja jednostki FA → base_unit (np. 500 g → kg)
        $faGroup = Units::baseGroupOf($unitInvoice);
        $baseGroup = Units::baseGroupOf($baseUnit);
        $direct = Units::convert($qtyInvoice, $unitInvoice, $baseUnit);
        if ($qtyBase === null && $direct !== null && $faGroup === $baseGroup && !Units::isPieceLikeUnit($unitInvoice)) {
            $qtyBase = $direct;
            $source = 'unit_convert';
            $meta['steps'][] = ['unit_convert', $direct];
        }

        // 3) Szt/op + waga/objętość w nazwie lub mapping
        if ($qtyBase === null && Units::isPieceLikeUnit($unitInvoice) && in_array($baseUnit, ['kg', 'l'], true)) {
            if ($faGroup !== $baseGroup || Units::isPieceLikeUnit($unitInvoice)) {
                $pack = PackSizeExtractor::extractPerPiece($externalName, $baseUnit, $externalDesc);
                if ($pack !== null && $pack['qty_base'] > 0) {
                    $qtyBase = $qtyInvoice * $pack['qty_base'];
                    $source = $pack['source'];
                    $meta['steps'][] = ['pack_extract', $pack];
                }
            }
        }

        // 4) Ta sama jednostka (szt→szt, kg→kg)
        if ($qtyBase === null && Units::normalizeLabel($unitInvoice) === $baseUnit) {
            $qtyBase = $qtyInvoice;
            $source = 'same_unit';
            $meta['steps'][] = ['same_unit'];
        }

        // 5) FA już w base (np. kg na FA, kg w magazynie) — bez pack z nazwy (E10)
        if ($qtyBase === null && $direct !== null) {
            $qtyBase = $direct;
            $source = 'unit_convert_fallback';
            $meta['steps'][] = ['unit_convert_fallback', $direct];
        }

        if ($qtyBase === null || $qtyBase <= 0) {
            return self::result(
                self::STATUS_BLOCKED,
                0,
                0,
                $meta,
                'Nie można przeliczyć „' . $unitInvoice . '” na „' . $baseUnit
                . '”. Uzupełnij mapowanie opakowania lub zmień jednostkę w słowniku.'
            );
        }

        $qtyBase = round($qtyBase, 6);
        $unitNetBase = $lineNet > 0 ? round($lineNet / $qtyBase, 4) : (
            $unitNetInvoice > 0 && $qtyInvoice > 0
                ? round(($unitNetInvoice * $qtyInvoice) / $qtyBase, 4)
                : 0.0
        );

        if ($unitNetBase <= 0) {
            return self::result(self::STATUS_BLOCKED, 0, 0, $meta, 'Nie można wyliczyć ceny jednostkowej w ' . $baseUnit . '.');
        }

        $meta['source'] = $source;
        $meta['qty_base'] = $qtyBase;
        $meta['unit_net_base'] = $unitNetBase;

        $status = self::STATUS_OK;
        $message = null;
        if ($source === 'name_weight' || $source === 'name_multipack') {
            $status = self::STATUS_WARN;
            $message = 'Gramatura z nazwy pozycji — sprawdź przeliczenie przed akceptacją.';
        }

        return self::result($status, $qtyBase, $unitNetBase, $meta, $message);
    }

    /**
     * @param array<string,mixed> $meta
     */
    private static function result(string $status, float $qtyBase, float $unitNetBase, array $meta, ?string $message): array
    {
        return [
            'status'            => $status,
            'qty_normalized'    => $qtyBase,
            'unit_net_normalized' => $unitNetBase,
            'normalization_meta'  => $meta,
            'message'           => $message,
            'is_ok'             => $status === self::STATUS_OK,
            'is_blocked'        => $status === self::STATUS_BLOCKED,
        ];
    }

    private static function toFloat(mixed $raw): float
    {
        if (is_int($raw) || is_float($raw)) {
            return (float) $raw;
        }
        $s = trim((string) $raw);
        if ($s === '') {
            return 0.0;
        }
        $s = str_replace(["\xC2\xA0", ' ', ','], ['', '', '.'], $s);

        return (float) $s;
    }

    /**
     * Lookup pack mapping: sh_product_mapping rozszerzone o pack_* (opcjonalne kolumny).
     *
     * @return array<string,mixed>|null
     */
    public static function loadPackMapping(PDO $pdo, int $tenantId, string $externalName, string $supplierNip = ''): ?array
    {
        $name = trim($externalName);
        if ($name === '') {
            return null;
        }
        if (!self::mappingHasPackColumns($pdo)) {
            return null;
        }
        $nip = preg_replace('/\D+/', '', $supplierNip) ?? '';
        if ($nip !== '') {
            $st = $pdo->prepare(
                "SELECT pack_qty_base, pack_invoice_unit
                   FROM sh_product_mapping
                  WHERE tenant_id = :tid AND LOWER(external_name) = LOWER(:ext)
                    AND (supplier_nip IS NULL OR supplier_nip = '' OR supplier_nip = :nip)
                    AND pack_qty_base IS NOT NULL AND pack_qty_base > 0
                  ORDER BY (supplier_nip = :nip2) DESC
                  LIMIT 1"
            );
            $st->execute([':tid' => $tenantId, ':ext' => $name, ':nip' => $nip, ':nip2' => $nip]);
        } else {
            $st = $pdo->prepare(
                "SELECT pack_qty_base, pack_invoice_unit
                   FROM sh_product_mapping
                  WHERE tenant_id = :tid AND LOWER(external_name) = LOWER(:ext)
                    AND pack_qty_base IS NOT NULL AND pack_qty_base > 0
                  LIMIT 1"
            );
            $st->execute([':tid' => $tenantId, ':ext' => $name]);
        }
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public static function learnPackMapping(
        PDO $pdo,
        int $tenantId,
        string $externalName,
        string $supplierNip,
        float $packQtyBase,
        string $packInvoiceUnit,
        string $internalSku
    ): void {
        if (!self::mappingHasPackColumns($pdo) || $packQtyBase <= 0) {
            return;
        }
        $nip = preg_replace('/\D+/', '', $supplierNip) ?? '';
        $nipVal = $nip !== '' ? $nip : null;
        try {
            $pdo->prepare(
                "INSERT INTO sh_product_mapping
                    (tenant_id, external_name, internal_sku, supplier_nip, pack_qty_base, pack_invoice_unit)
                 VALUES (:tid, :ext, :sku, :nip, :pb, :piu)
                 ON DUPLICATE KEY UPDATE
                    internal_sku = VALUES(internal_sku),
                    pack_qty_base = VALUES(pack_qty_base),
                    pack_invoice_unit = VALUES(pack_invoice_unit)"
            )->execute([
                ':tid' => $tenantId,
                ':ext' => trim($externalName),
                ':sku' => trim($internalSku),
                ':nip' => $nipVal,
                ':pb'  => $packQtyBase,
                ':piu' => Units::normalizeLabel($packInvoiceUnit),
            ]);
        } catch (\Throwable $e) {
            error_log('[InvoiceLineQtyNormalizer.learnPack] ' . $e->getMessage());
        }
    }

    private static ?bool $packCols = null;

    private static function mappingHasPackColumns(PDO $pdo): bool
    {
        if (self::$packCols !== null) {
            return self::$packCols;
        }
        try {
            $db = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tn AND COLUMN_NAME = :cn'
            );
            $st->execute([':db' => $db, ':tn' => 'sh_product_mapping', ':cn' => 'pack_qty_base']);
            self::$packCols = ((int) $st->fetchColumn()) > 0;
        } catch (\Throwable $e) {
            self::$packCols = false;
        }

        return self::$packCols;
    }

    public static function lineColumnsExist(PDO $pdo): bool
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
            $st->execute([':db' => $db, ':tn' => 'sh_ksef_invoice_lines', ':cn' => 'qty_normalized']);
            $cache = ((int) $st->fetchColumn()) > 0;
        } catch (\Throwable $e) {
            $cache = false;
        }

        return $cache;
    }
}
