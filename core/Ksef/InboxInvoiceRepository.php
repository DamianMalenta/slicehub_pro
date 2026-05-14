<?php

declare(strict_types=1);

namespace SliceHub\Ksef;

/**
 * Wspólny zapis sparsowanej faktury FA(2) do sh_ksef_invoices + linii.
 * Używany przez worker KSeF, poll_now i (nagłówek) upload XML — jedna ścieżka INSERT, mniej driftu.
 */
final class InboxInvoiceRepository
{
    /**
     * @param array<string,mixed> $parsed wynik Parser::parse przy success=true
     */
    public static function insertInvoiceWithLines(
        \PDO $pdo,
        int $tenantId,
        ?string $ksefReferenceId,
        string $xml,
        array $parsed,
        string $invoiceNumberFallback
    ): int {
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
                     xml_blob, parsed_json, status)
                 VALUES
                    (:tid, :ref,
                     :snip, :sname, :saddr,
                     :bnip, :bname,
                     :inum, :issd, :sald, :payd, :cur,
                     :tnet, :tvat, :tgross,
                     :xml, :pjson, 'draft')"
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
     * AutoScan dla wszystkich linii faktury (jak worker / poll_now).
     */
    public static function matchInvoiceLines(\PDO $pdo, int $tenantId, int $invoiceId): void
    {
        $matchUpd = $pdo->prepare(
            "UPDATE sh_ksef_invoice_lines
                SET resolved_sku = :sku, match_type = :mt, match_confidence = :conf,
                    match_candidates_json = :cand, resolved_at = NOW()
              WHERE id = :id"
        );
        $linesStmt = $pdo->prepare(
            "SELECT id, external_name FROM sh_ksef_invoice_lines WHERE ksef_invoice_id = :iid"
        );
        $linesStmt->execute([':iid' => $invoiceId]);
        foreach ($linesStmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $r = \AutoScanEngine::match($pdo, $tenantId, (string) $row['external_name']);
            $matchUpd->execute([
                ':sku'  => $r['sku'],
                ':mt'   => $r['match_type'] ?? 'NONE',
                ':conf' => $r['confidence'] ?? 0,
                ':cand' => json_encode($r['candidates'] ?? [], JSON_UNESCAPED_UNICODE),
                ':id'   => $row['id'],
            ]);
        }
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
