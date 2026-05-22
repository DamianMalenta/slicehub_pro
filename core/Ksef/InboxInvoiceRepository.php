<?php

declare(strict_types=1);

namespace SliceHub\Ksef;

/**
 * Wspólny zapis sparsowanej faktury FA(2) do sh_ksef_invoices + linii.
 * Używany przez worker KSeF, poll_now i (nagłówek) upload XML — jedna ścieżka INSERT, mniej driftu.
 */
final class InboxInvoiceRepository
{
    private static ?bool $lineTypeColumnExists = null;

    /**
     * @param array<string,mixed> $parsed wynik Parser::parse przy success=true
     */
    public static function insertInvoiceWithLines(
        \PDO $pdo,
        int $tenantId,
        ?string $ksefReferenceId,
        string $xml,
        array $parsed,
        string $invoiceNumberFallback,
        string $status = 'draft',
        ?string $statusMessage = null
    ): int {
        if (!in_array($status, ['draft', 'error', 'new'], true)) {
            $status = 'draft';
        }
        $invoiceNo = trim((string) ($parsed['invoice']['number'] ?? ''));
        if ($invoiceNo === '') {
            $invoiceNo = $invoiceNumberFallback;
        }

        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare(
                "INSERT INTO sh_ksef_invoices
                    (tenant_id, ksef_reference_id,
                     supplier_nip, supplier_name, supplier_address,
                     buyer_nip, buyer_name,
                     invoice_number, issue_date, sale_date, payment_due_date, currency,
                     total_net_minor, total_vat_minor, total_gross_minor,
                     xml_blob, parsed_json, status, status_message)
                 VALUES
                    (:tid, :ref,
                     :snip, :sname, :saddr,
                     :bnip, :bname,
                     :inum, :issd, :sald, :payd, :cur,
                     :tnet, :tvat, :tgross,
                     :xml, :pjson, :st, :stmsg)"
            );
            $st->execute([
                ':tid'    => $tenantId,
                ':ref'    => $ksefReferenceId,
                ':snip'   => $parsed['supplier']['nip'] ?: null,
                ':sname'  => $parsed['supplier']['name'] ?: null,
                ':saddr'  => $parsed['supplier']['address'] ?: null,
                ':bnip'   => $parsed['buyer']['nip'] ?: null,
                ':bname'  => $parsed['buyer']['name'] ?: null,
                ':inum'   => $invoiceNo,
                ':issd'   => $parsed['invoice']['issue_date'],
                ':sald'   => $parsed['invoice']['sale_date'],
                ':payd'   => $parsed['invoice']['payment_due_date'],
                ':cur'    => $parsed['invoice']['currency'] ?: 'PLN',
                ':tnet'   => $parsed['totals']['total_net_minor'] ?? 0,
                ':tvat'   => $parsed['totals']['total_vat_minor'] ?? 0,
                ':tgross' => $parsed['totals']['total_gross_minor'] ?? 0,
                ':xml'    => $xml,
                ':pjson'  => json_encode($parsed, JSON_UNESCAPED_UNICODE),
                ':st'     => $status,
                ':stmsg'  => $statusMessage,
            ]);
            $invoiceId = (int) $pdo->lastInsertId();

            $stL = $pdo->prepare(
                "INSERT INTO sh_ksef_invoice_lines
                    (ksef_invoice_id, line_no, external_name, external_description,
                     gtu_code, pkwiu, unit, qty, unit_net, line_net_minor, vat_rate)
                 VALUES
                    (:iid, :lno, :name, :desc, :gtu, :pkwiu, :unit, :qty, :unet, :lnet, :vat)"
            );
            foreach ($parsed['lines'] as $line) {
                $stL->execute([
                    ':iid'   => $invoiceId,
                    ':lno'   => $line['line_no'],
                    ':name'  => $line['external_name'],
                    ':desc'  => $line['description'] ?: null,
                    ':gtu'   => $line['gtu_code'] ?: null,
                    ':pkwiu' => $line['pkwiu'] ?: null,
                    ':unit'  => $line['unit'] ?: null,
                    ':qty'   => $line['qty'],
                    ':unet'  => $line['unit_net'],
                    ':lnet'  => $line['line_net_minor'],
                    ':vat'   => $line['vat_rate'],
                ]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $invoiceId;
    }

    /**
     * AutoScan dla linii faktury — tylko INVENTORY gdy istnieje kolumna line_type (m057),
     * żeby nie nadpisywać dopasowań SKU na liniach OPEX (EXPENSE).
     */
    /**
     * @return array{total:int, auto_accept:int, EXACT?:int, ALIAS?:int, NAME?:int, FUZZY?:int, NONE?:int}
     */
    public static function matchInvoiceLines(\PDO $pdo, int $tenantId, int $invoiceId): array
    {
        $matchUpd = $pdo->prepare(
            "UPDATE sh_ksef_invoice_lines
                SET resolved_sku = :sku, match_type = :mt, match_confidence = :conf,
                    match_candidates_json = :cand, resolved_at = NOW()
              WHERE id = :id"
        );
        $sql = 'SELECT id, external_name FROM sh_ksef_invoice_lines WHERE ksef_invoice_id = :iid';
        if (self::invoiceLinesHaveLineTypeColumn($pdo)) {
            $sql .= " AND COALESCE(line_type, 'INVENTORY') = 'INVENTORY'";
        }
        $sql .= ' ORDER BY line_no';
        $nipSt = $pdo->prepare(
            'SELECT supplier_nip FROM sh_ksef_invoices WHERE id = :iid AND tenant_id = :tid LIMIT 1'
        );
        $nipSt->execute([':iid' => $invoiceId, ':tid' => $tenantId]);
        $supplierNip = (string) ($nipSt->fetchColumn() ?: '');

        $linesStmt = $pdo->prepare($sql);
        $linesStmt->execute([':iid' => $invoiceId]);
        $stats = ['total' => 0, 'auto_accept' => 0];
        foreach ($linesStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $r = \AutoScanEngine::match($pdo, $tenantId, (string) $row['external_name'], null, $supplierNip);
            $mt = (string) ($r['match_type'] ?? 'NONE');
            $conf = (int) ($r['confidence'] ?? 0);
            $matchUpd->execute([
                ':sku'  => $r['sku'],
                ':mt'   => $mt,
                ':conf' => $conf,
                ':cand' => json_encode($r['candidates'] ?? [], JSON_UNESCAPED_UNICODE),
                ':id'   => $row['id'],
            ]);
            $stats['total']++;
            $stats[$mt] = ($stats[$mt] ?? 0) + 1;
            if ($conf >= 70 && $mt !== 'NONE' && !empty($r['sku'])) {
                $stats['auto_accept']++;
            }
        }

        return $stats;
    }

    private static function invoiceLinesHaveLineTypeColumn(\PDO $pdo): bool
    {
        if (self::$lineTypeColumnExists !== null) {
            return self::$lineTypeColumnExists;
        }
        try {
            $db = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
            if ($db === '') {
                self::$lineTypeColumnExists = false;

                return false;
            }
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLUMNS
                  WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tn AND COLUMN_NAME = :cn'
            );
            $st->execute([':db' => $db, ':tn' => 'sh_ksef_invoice_lines', ':cn' => 'line_type']);
            self::$lineTypeColumnExists = ((int) $st->fetchColumn()) > 0;
        } catch (\Throwable $e) {
            self::$lineTypeColumnExists = false;
        }

        return self::$lineTypeColumnExists;
    }

    public static function isMysqlDuplicateKey(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }
        $info = $e->errorInfo;

        return ($info[0] ?? '') === '23000' || (int) ($info[1] ?? 0) === 1062;
    }
}
