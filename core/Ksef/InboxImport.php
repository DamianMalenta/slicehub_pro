<?php

declare(strict_types=1);

namespace SliceHub\Ksef;

/**
 * Jedna ścieżka importu FA(2)/FA(3) → sh_ksef_invoices (worker, poll_now, upload XML).
 * Puste / nietypowe dokumenty KSeF trafiają do status=error zamiast „pustego” draft.
 */
final class InboxImport
{
    /**
     * @return array{
     *   invoice_id: int,
     *   status: string,
     *   procurement_ok: bool,
     *   quality_messages: list<string>
     * }
     */
    /**
     * @param array<string,mixed>|null $preParsed wynik parse() / parseAndVerifyBuyer() — unika drugiego parse w upload
     * @param string|null $expectedBuyerNip tylko gdy $preParsed=null (upload bez wcześniejszego parse)
     */
    public static function importFromXml(
        \PDO $pdo,
        int $tenantId,
        ?string $ksefReferenceId,
        string $xml,
        string $invoiceNumberFallback,
        ?array $preParsed = null,
        ?string $expectedBuyerNip = null
    ): array {
        $parser = new Parser();
        $parsed = self::resolveParsed($parser, $xml, $preParsed, $expectedBuyerNip);
        $quality = $parser->assessProcurementQuality($parsed);
        $procurementOk = (bool) ($quality['procurement_ok'] ?? false);
        $status = $procurementOk ? 'draft' : 'error';
        $statusMessage = '';
        if (!$procurementOk) {
            $msgs = is_array($quality['messages'] ?? null) ? $quality['messages'] : [];
            $statusMessage = implode(' ', $msgs);
        }

        $invoiceId = InboxInvoiceRepository::insertInvoiceWithLines(
            $pdo,
            $tenantId,
            $ksefReferenceId,
            $xml,
            $parsed,
            $invoiceNumberFallback,
            $status,
            $statusMessage !== '' ? $statusMessage : null
        );

        $matchStats = ['total' => 0, 'auto_accept' => 0];
        if ($procurementOk) {
            $matchStats = InboxInvoiceRepository::matchInvoiceLines($pdo, $tenantId, $invoiceId);
            require_once __DIR__ . '/InboxQtyNormalize.php';
            $sn = (string) ($parsed['supplier']['nip'] ?? '');
            InboxQtyNormalize::refreshInvoiceLines($pdo, $tenantId, $invoiceId, $sn, true);
        }

        return [
            'invoice_id'         => $invoiceId,
            'status'             => $status,
            'procurement_ok'     => $procurementOk,
            'quality_messages'   => is_array($quality['messages'] ?? null) ? $quality['messages'] : [],
            'match_stats'        => $matchStats,
            'parsed'             => $parsed,
        ];
    }

    /**
     * Jedno parse na ścieżkę importu (bez powielania z upload_xml / reassess).
     *
     * @param array<string,mixed>|null $preParsed
     * @return array<string,mixed>
     */
    private static function resolveParsed(
        Parser $parser,
        string $xml,
        ?array $preParsed,
        ?string $expectedBuyerNip
    ): array {
        if ($preParsed !== null) {
            if (!($preParsed['success'] ?? false)) {
                $err = is_array($preParsed['errors'] ?? null) ? $preParsed['errors'] : ['Niepoprawny wynik parse.'];
                throw new \RuntimeException('Parser: ' . implode('; ', $err));
            }

            return $preParsed;
        }

        $expected = trim((string) ($expectedBuyerNip ?? ''));
        if ($expected !== '') {
            [$parsed, $errors] = $parser->parseAndVerifyBuyer($xml, $expected);
            if ($parsed === null) {
                throw new \RuntimeException(implode('; ', $errors ?: ['Parse failed.']));
            }

            return $parsed;
        }

        $parsed = $parser->parse($xml);
        if (!$parsed['success']) {
            throw new \RuntimeException('Parser: ' . implode('; ', $parsed['errors']));
        }

        return $parsed;
    }

    /**
     * Ponowna ocena istniejącej faktury z xml_blob (naprawa starych „pustych” draftów).
     *
     * @return array{status:string, procurement_ok:bool, quality_messages:list<string>, totals:array<string,int>}
     */
    public static function reassessExistingInvoice(\PDO $pdo, int $tenantId, int $invoiceId): array
    {
        $st = $pdo->prepare(
            'SELECT id, status, xml_blob FROM sh_ksef_invoices WHERE id = :id AND tenant_id = :tid LIMIT 1'
        );
        $st->execute([':id' => $invoiceId, ':tid' => $tenantId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException('Faktura nie istnieje.');
        }
        if (in_array((string) ($row['status'] ?? ''), ['accepted'], true)) {
            throw new \RuntimeException('Nie można ponownie ocenić faktury zaakceptowanej.');
        }

        $xml = (string) ($row['xml_blob'] ?? '');
        if (trim($xml) === '') {
            throw new \RuntimeException('Brak xml_blob — pobierz ponownie z KSeF lub wgraj XML.');
        }

        $parser = new Parser();
        $parsed = self::resolveParsed($parser, $xml, null, null);
        $quality = $parser->assessProcurementQuality($parsed);
        $procurementOk = (bool) ($quality['procurement_ok'] ?? false);
        $status = $procurementOk ? 'draft' : 'error';
        $statusMessage = '';
        if (!$procurementOk) {
            $msgs = is_array($quality['messages'] ?? null) ? $quality['messages'] : [];
            $statusMessage = implode(' ', $msgs);
        }

        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                'UPDATE sh_ksef_invoices SET
                    supplier_nip = :snip, supplier_name = :sname, supplier_address = :saddr,
                    buyer_nip = :bnip, buyer_name = :bname,
                    invoice_number = COALESCE(NULLIF(:inum, \'\'), invoice_number),
                    issue_date = :issd, sale_date = :sald, payment_due_date = :payd, currency = :cur,
                    total_net_minor = :tnet, total_vat_minor = :tvat, total_gross_minor = :tgross,
                    parsed_json = :pjson, status = :st, status_message = :msg
                 WHERE id = :id AND tenant_id = :tid'
            )->execute([
                ':snip'  => $parsed['supplier']['nip'] ?: null,
                ':sname' => $parsed['supplier']['name'] ?: null,
                ':saddr' => $parsed['supplier']['address'] ?: null,
                ':bnip'  => $parsed['buyer']['nip'] ?: null,
                ':bname' => $parsed['buyer']['name'] ?: null,
                ':inum'  => trim((string) ($parsed['invoice']['number'] ?? '')),
                ':issd'  => $parsed['invoice']['issue_date'],
                ':sald'  => $parsed['invoice']['sale_date'],
                ':payd'  => $parsed['invoice']['payment_due_date'],
                ':cur'   => $parsed['invoice']['currency'] ?: 'PLN',
                ':tnet'  => $parsed['totals']['total_net_minor'] ?? 0,
                ':tvat'  => $parsed['totals']['total_vat_minor'] ?? 0,
                ':tgross'=> $parsed['totals']['total_gross_minor'] ?? 0,
                ':pjson' => json_encode($parsed, JSON_UNESCAPED_UNICODE),
                ':st'    => $status,
                ':msg'   => $statusMessage !== '' ? $statusMessage : null,
                ':id'    => $invoiceId,
                ':tid'   => $tenantId,
            ]);

            $pdo->prepare('DELETE FROM sh_ksef_invoice_lines WHERE ksef_invoice_id = :iid')
                ->execute([':iid' => $invoiceId]);

            $stL = $pdo->prepare(
                'INSERT INTO sh_ksef_invoice_lines
                    (ksef_invoice_id, line_no, external_name, external_description,
                     gtu_code, pkwiu, unit, qty, unit_net, line_net_minor, vat_rate)
                 VALUES
                    (:iid, :lno, :name, :desc, :gtu, :pkwiu, :unit, :qty, :unet, :lnet, :vat)'
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

        $matchStats = ['total' => 0, 'auto_accept' => 0];
        if ($procurementOk) {
            $matchStats = InboxInvoiceRepository::matchInvoiceLines($pdo, $tenantId, $invoiceId);
            require_once __DIR__ . '/InboxQtyNormalize.php';
            $sn = (string) ($parsed['supplier']['nip'] ?? '');
            InboxQtyNormalize::refreshInvoiceLines($pdo, $tenantId, $invoiceId, $sn, true);
        }

        return [
            'status'           => $status,
            'procurement_ok'   => $procurementOk,
            'quality_messages' => is_array($quality['messages'] ?? null) ? $quality['messages'] : [],
            'totals'           => [
                'total_net_minor'   => (int) ($parsed['totals']['total_net_minor'] ?? 0),
                'total_vat_minor'   => (int) ($parsed['totals']['total_vat_minor'] ?? 0),
                'total_gross_minor' => (int) ($parsed['totals']['total_gross_minor'] ?? 0),
            ],
            'match_stats'      => $matchStats,
        ];
    }
}
