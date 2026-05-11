<?php

declare(strict_types=1);

namespace SliceHub\Ksef;

/**
 * SliceHub — FA(2) XML Parser
 *
 * Lekki parser polskiego standardu FA(2) (KSeF) używany w F3 do
 * obsługi manual upload XML i w F4 do parsowania response z KSeF API.
 *
 * Schema reference: https://ksef.podatki.gov.pl/ (Schemat_FA_2)
 *
 * Struktura FA(2):
 *   <Faktura xmlns="http://crd.gov.pl/wzor/2023/06/29/12648/">
 *     <Naglowek>...</Naglowek>
 *     <Podmiot1>... sprzedawca ...</Podmiot1>
 *     <Podmiot2>... nabywca (my) ...</Podmiot2>
 *     <Fa>
 *       <KodWaluty>PLN</KodWaluty>
 *       <P_1>2026-05-11</P_1>           (DataWystawienia)
 *       <P_1M>WARSZAWA</P_1M>           (MiejsceWystawienia)
 *       <P_6>2026-05-11</P_6>           (DataSprzedazy / KoniecOkresu)
 *       <P_2>FV/2026/05/123</P_2>       (NumerFaktury)
 *       <FaWiersz>...linie...</FaWiersz>
 *       <P_13_1>...</P_13_1>            (SumaWartosciSprzedazyNetto)
 *       <P_14_1>...</P_14_1>            (KwotaPodatku)
 *       <P_15>...</P_15>                (NaleznoscOgolem)
 *     </Fa>
 *   </Faktura>
 *
 * FaWiersz:
 *   <NrWierszaFa>1</NrWierszaFa>
 *   <P_7>Mąka Caputo Tipo 00</P_7>      (Nazwa)
 *   <P_8A>kg</P_8A>                     (Miara)
 *   <P_8B>25.0000</P_8B>                (Ilość)
 *   <P_9A>4.5000</P_9A>                 (CenaJednNetto)
 *   <P_11>112.50</P_11>                 (WartoscNetto)
 *   <P_12>5</P_12>                      (StawkaVAT)
 *   <GTU>GTU_01</GTU>                   (opcjonalnie)
 *
 * Dla MVP F3: parsujemy najczęściej używane pola. Pełne XSD ma >1000 pól,
 * większość rzadko używana. Nieparsowane pola zostają w `xml_blob`
 * w `sh_ksef_invoices.xml_blob` na potrzeby compliance / archiwum.
 *
 * Konstytucja v5 § Prawo IV (Zero Zaufania): walidacja na poziomie XML
 * przed zapisem do bazy. Brak namespace warningu / structurally invalid →
 * exception. Każde DateTime parsowane przez DateTimeImmutable z catch.
 */
class Parser
{
    public const FA2_NS = 'http://crd.gov.pl/wzor/2023/06/29/12648/';

    /** @var list<string> */
    private array $errors = [];

    /** @var list<string> */
    private array $warnings = [];

    /**
     * Parse FA(2) XML string into structured array.
     *
     * @return array{
     *   success: bool,
     *   errors: list<string>,
     *   warnings: list<string>,
     *   header: array<string,mixed>,
     *   supplier: array<string,mixed>,
     *   buyer: array<string,mixed>,
     *   invoice: array<string,mixed>,
     *   lines: list<array<string,mixed>>,
     *   totals: array<string,mixed>
     * }
     */
    public function parse(string $xml): array
    {
        $this->errors = [];
        $this->warnings = [];

        $xml = trim($xml);
        if ($xml === '') {
            return $this->fail('Pusty XML.');
        }

        // BOM strip jeśli jest
        if (str_starts_with($xml, "\xEF\xBB\xBF")) {
            $xml = substr($xml, 3);
        }

        libxml_use_internal_errors(true);
        $doc = @simplexml_load_string($xml);
        if ($doc === false) {
            $libErrors = libxml_get_errors();
            libxml_clear_errors();
            $msg = 'Niepoprawny XML: ';
            foreach ($libErrors as $e) {
                $msg .= trim($e->message) . '; ';
            }
            return $this->fail(rtrim($msg, '; '));
        }

        // Detekcja namespace — FA(2) używa namespace MF, ale też akceptujemy bez NS
        // (np. ręcznie przygotowane przykłady, sandbox bez prawidłowego envelope)
        $namespaces = $doc->getDocNamespaces(true);
        $ns = isset($namespaces[''])
            ? (string) $namespaces['']
            : (in_array(self::FA2_NS, $namespaces, true) ? self::FA2_NS : '');

        // Walidacja struktury — root powinien być Faktura
        if ($doc->getName() !== 'Faktura') {
            return $this->fail('Root XML nie jest <Faktura>. FA(2) wymaga <Faktura xmlns=...>');
        }

        $xpath = (string) $ns;
        $body = $xpath !== '' ? $doc->children($xpath) : $doc;

        try {
            return [
                'success'  => true,
                'errors'   => [],
                'warnings' => $this->warnings,
                'header'   => $this->parseHeader($body),
                'supplier' => $this->parsePodmiot($body, 'Podmiot1'),
                'buyer'    => $this->parsePodmiot($body, 'Podmiot2'),
                'invoice'  => $this->parseInvoice($body),
                'lines'    => $this->parseLines($body),
                'totals'   => $this->parseTotals($body),
            ];
        } catch (\Throwable $e) {
            return $this->fail('Wyjątek parsera: ' . $e->getMessage());
        }
    }

    /**
     * Convenience: parse z weryfikacją że buyer.nip == expected_buyer_nip.
     * Zwraca [parsed, $errors=[]] albo [null, $errors] gdy nie pasuje.
     *
     * @return array{0: ?array, 1: list<string>}
     */
    public function parseAndVerifyBuyer(string $xml, string $expectedBuyerNip): array
    {
        $parsed = $this->parse($xml);
        if (!$parsed['success']) {
            return [null, $parsed['errors']];
        }
        $buyerNip = $parsed['buyer']['nip'] ?? '';
        $expected = preg_replace('/\D+/', '', $expectedBuyerNip) ?? '';
        $actual = preg_replace('/\D+/', '', (string) $buyerNip) ?? '';
        if ($expected !== '' && $actual !== '' && $expected !== $actual) {
            return [null, [
                "Faktura wystawiona na inny NIP nabywcy. Oczekiwano: {$expected}, w XML: {$actual}.",
            ]];
        }
        return [$parsed, []];
    }

    // =========================================================================
    // Private helpers
    // =========================================================================

    private function fail(string $msg): array
    {
        $this->errors[] = $msg;
        return [
            'success'  => false,
            'errors'   => $this->errors,
            'warnings' => $this->warnings,
            'header'   => [],
            'supplier' => [],
            'buyer'    => [],
            'invoice'  => [],
            'lines'    => [],
            'totals'   => [],
        ];
    }

    private function parseHeader(\SimpleXMLElement $body): array
    {
        $h = $body->Naglowek ?? null;
        if ($h === null) return [];
        return [
            'kod_formularza'   => (string) ($h->KodFormularza ?? ''),
            'kod_systemowy'    => isset($h->KodFormularza) ? (string) ($h->KodFormularza['kodSystemowy'] ?? '') : '',
            'wariant'          => isset($h->KodFormularza) ? (string) ($h->KodFormularza['wariantFormularza'] ?? '') : '',
            'data_wytworzenia' => (string) ($h->DataWytworzeniaFa ?? ''),
            'system_info'      => (string) ($h->SystemInfo ?? ''),
        ];
    }

    private function parsePodmiot(\SimpleXMLElement $body, string $tag): array
    {
        $p = $body->{$tag} ?? null;
        if ($p === null) return [];

        // Identyfikator: DaneIdentyfikacyjne.NIP / KodKraju
        $ident = $p->DaneIdentyfikacyjne ?? null;
        $nip = $ident ? (string) ($ident->NIP ?? '') : '';
        $name = $ident ? (string) ($ident->Nazwa ?? '') : '';

        // Adres: Adres.AdresL1, AdresL2, KodKraju
        $adres = $p->Adres ?? null;
        $addressParts = [];
        if ($adres !== null) {
            foreach (['AdresL1', 'AdresL2', 'KodPocztowy', 'Miejscowosc'] as $f) {
                if (isset($adres->{$f}) && trim((string) $adres->{$f}) !== '') {
                    $addressParts[] = trim((string) $adres->{$f});
                }
            }
        }
        $address = implode(', ', $addressParts);

        return [
            'nip'      => $nip,
            'name'     => $name,
            'address'  => $address,
            'email'    => (string) ($p->Email ?? ''),
            'telefon'  => (string) ($p->Telefon ?? ''),
        ];
    }

    private function parseInvoice(\SimpleXMLElement $body): array
    {
        $fa = $body->Fa ?? null;
        if ($fa === null) return [];

        // Pola Fa.P_* (numerowane standardem FA(2))
        $invoiceNumber = (string) ($fa->P_2 ?? '');
        $dataWystawienia = (string) ($fa->P_1 ?? '');
        $dataSprzedazy = (string) ($fa->P_6 ?? '');
        if ($dataSprzedazy === '') {
            // FA(2) okres: P_6 (start) i P_6_2 (koniec) — bierzemy P_6
            $dataSprzedazy = (string) ($fa->P_6_2 ?? $dataWystawienia);
        }

        // Termin płatności — Płatność.TerminPlatnosci.Termin
        $platnosc = $fa->Platnosc ?? null;
        $paymentDue = '';
        if ($platnosc !== null && isset($platnosc->TerminPlatnosci)) {
            $paymentDue = (string) ($platnosc->TerminPlatnosci->Termin ?? '');
        }

        $currency = (string) ($fa->KodWaluty ?? 'PLN');

        return [
            'number'           => $invoiceNumber,
            'issue_date'       => $this->normalizeDate($dataWystawienia),
            'sale_date'        => $this->normalizeDate($dataSprzedazy),
            'payment_due_date' => $paymentDue !== '' ? $this->normalizeDate($paymentDue) : null,
            'currency'         => $currency !== '' ? $currency : 'PLN',
            'place_issued'     => (string) ($fa->P_1M ?? ''),
            'invoice_type'     => (string) ($fa->RodzajFaktury ?? ''),
        ];
    }

    private function parseLines(\SimpleXMLElement $body): array
    {
        $fa = $body->Fa ?? null;
        if ($fa === null) return [];

        $lines = [];
        $idx = 0;
        foreach ($fa->FaWiersz as $row) {
            $idx++;
            $nr = (int) ((string) ($row->NrWierszaFa ?? $idx));
            $name = trim((string) ($row->P_7 ?? ''));
            $qty = $this->parseDecimal((string) ($row->P_8B ?? '0'));
            $unitNet = $this->parseDecimal((string) ($row->P_9A ?? '0'));
            $lineNet = $this->parseDecimal((string) ($row->P_11 ?? '0'));
            $vatRate = $this->parseVatRate((string) ($row->P_12 ?? '0'));

            // GTU może być wieloraka — FA(2) zwykle GTU_01...GTU_13 jako bool flagi
            $gtu = '';
            foreach ($row->children() as $child) {
                $childName = $child->getName();
                if (str_starts_with($childName, 'GTU_') && (string) $child === '1') {
                    $gtu = $childName;
                    break;
                }
            }
            // Albo bezpośrednio <GTU>kod</GTU>
            if ($gtu === '' && isset($row->GTU)) {
                $gtu = trim((string) $row->GTU);
            }

            // PKWiU może być w P_7B albo PKWiU
            $pkwiu = trim((string) ($row->P_7B ?? $row->PKWiU ?? ''));

            $lines[] = [
                'line_no'        => $nr,
                'external_name'  => $name,
                'description'    => trim((string) ($row->P_7A ?? '')),
                'gtu_code'       => $gtu,
                'pkwiu'          => $pkwiu,
                'unit'           => trim((string) ($row->P_8A ?? '')),
                'qty'            => $qty,
                'unit_net'       => $unitNet,
                'line_net_minor' => (int) round($lineNet * 100),
                'vat_rate'       => $vatRate,
            ];
        }
        return $lines;
    }

    private function parseTotals(\SimpleXMLElement $body): array
    {
        $fa = $body->Fa ?? null;
        if ($fa === null) return [];

        // FA(2) suma sprzedaży: P_13_1 (netto 23%) + P_13_2 (netto 8%) + P_13_3 (netto 5%) + ...
        // dla MVP: P_13_1 + P_13_2 + P_13_3 + P_13_4 + P_13_5 + P_13_6 + P_13_7 (zw.)
        $totalNet = 0.0;
        for ($i = 1; $i <= 7; $i++) {
            $totalNet += $this->parseDecimal((string) ($fa->{"P_13_{$i}"} ?? '0'));
        }

        $totalVat = 0.0;
        for ($i = 1; $i <= 6; $i++) {
            $totalVat += $this->parseDecimal((string) ($fa->{"P_14_{$i}"} ?? '0'));
        }

        $totalGross = $this->parseDecimal((string) ($fa->P_15 ?? '0'));

        return [
            'total_net_minor'   => (int) round($totalNet * 100),
            'total_vat_minor'   => (int) round($totalVat * 100),
            'total_gross_minor' => (int) round($totalGross * 100),
        ];
    }

    private function parseDecimal(string $val): float
    {
        $v = trim(str_replace([',', ' '], ['.', ''], $val));
        if ($v === '') return 0.0;
        return (float) $v;
    }

    private function parseVatRate(string $val): float
    {
        $v = trim($val);
        // FA(2) może zawierać: '23', '8', '5', '0', 'zw' (zwolnione), 'np' (nie podlega), 'oo' (odwrotne obciążenie)
        if (in_array(strtolower($v), ['zw', 'np', 'oo'], true)) {
            return 0.0;
        }
        return $this->parseDecimal($v);
    }

    private function normalizeDate(string $val): ?string
    {
        $v = trim($val);
        if ($v === '') return null;
        try {
            // FA(2) date format: YYYY-MM-DD
            $dt = new \DateTimeImmutable($v);
            return $dt->format('Y-m-d');
        } catch (\Throwable $e) {
            $this->warnings[] = "Nieprawidłowa data: '{$v}'";
            return null;
        }
    }
}
