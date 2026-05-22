<?php

declare(strict_types=1);

namespace SliceHub\Ksef;

/**
 * SliceHub — FA(2) / FA(3) XML Parser (KSeF)
 *
 * Lekki parser struktury logicznej faktury (KSeF) — F3 upload + F4 odpowiedź GET /invoices/ksef/{ksefNumber}.
 * Od 2026-02 obowiązuje FA(3) (`xmlns` inny niż FA(2)); węzły wyszukiwane po **local-name()**,
 * żeby ominąć problem prefiksów / domyślnego namespace w SimpleXML.
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
 *
 * Warstwy (nie powielaj w inbox / worker / Client):
 * - Client::validateInvoiceXmlBody() — transport HTTP (pusty body, JSON/HTML, brak <Faktura>)
 * - parse() — well-formed XML, root Faktura, węzeł Fa, minimalna tożsamość dokumentu
 * - enrichParsedTotals() (wewnątrz parse) — normalizacja sum z FaWiersz, nie gate workflow
 * - parseAndVerifyBuyer() — upload ręczny: nabywca = NIP tenanta (poza KSeF poll)
 * - assessProcurementQuality() — draft vs error (zakupy); po parse(), przed INSERT
 * - inbox accept / rescan — SKU, OPEX, PZ (operacyjne; poza Parserem)
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

        // Walidacja struktury — root powinien być Faktura (lokalna nazwa, bez prefiksu)
        if ($doc->getName() !== 'Faktura') {
            return $this->fail('Root XML nie jest <Faktura>. Oczekiwany dokument FA(2)/FA(3) z KSeF.');
        }

        try {
            $header = $this->parseHeaderFromDoc($doc);
            $supplier = $this->parsePodmiotElement(self::findFirstElement($doc, 'Podmiot1'));
            $buyer = $this->parsePodmiotElement(self::findFirstElement($doc, 'Podmiot2'));
            $fa = self::findFirstElement($doc, 'Fa');
            if ($fa === null) {
                return $this->fail('Brak węzła <Fa> — nie rozpoznano struktury faktury (FA(2)/FA(3) lub zagnieżdżenie).');
            }
            $invoice = $this->parseInvoiceFromFa($fa);
            $lines = $this->parseLinesFromFa($fa);
            $totals = $this->parseTotalsFromFa($fa);

            $parsed = [
                'success'  => true,
                'errors'   => [],
                'warnings' => $this->warnings,
                'header'   => $header,
                'supplier' => $supplier,
                'buyer'    => $buyer,
                'invoice'  => $invoice,
                'lines'    => $lines,
                'totals'   => $totals,
            ];
            $parsed = $this->enrichParsedTotals($parsed);

            $nipS = trim((string) ($supplier['nip'] ?? ''));
            $invNo = trim((string) ($invoice['number'] ?? ''));
            if ($nipS === '' && $invNo === '' && $lines === []) {
                return $this->fail(
                    'Faktura XML bez rozpoznanych danych (brak NIP dostawcy, numeru i linii). '
                    . 'Możliwy FA(3) z innymi nazwami pól lub treść pośrednia — sprawdź xml_blob w bazie.'
                );
            }

            return $parsed;
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

    /**
     * Uzupełnia sumy nagłówka z pozycji FaWiersz, gdy P_13/P_15 w XML są puste (częste FA(3) / nietypowe układy).
     *
     * @param array<string,mixed> $parsed
     * @return array<string,mixed>
     */
    public function enrichParsedTotals(array $parsed): array
    {
        if (!self::headerTotalsAreZero($parsed)) {
            return $parsed;
        }

        $lines = is_array($parsed['lines'] ?? null) ? $parsed['lines'] : [];
        if ($lines === []) {
            return $parsed;
        }

        $sumNet = 0;
        $sumVat = 0;
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $lineNet = self::lineNetMinor($line);
            if ($lineNet <= 0) {
                continue;
            }
            $sumNet += $lineNet;
            $vatRate = (float) ($line['vat_rate'] ?? 0);
            if ($vatRate > 0) {
                $sumVat += (int) round($lineNet * $vatRate / 100);
            }
        }

        if ($sumNet <= 0) {
            return $parsed;
        }

        $parsed['totals'] = [
            'total_net_minor'    => $sumNet,
            'total_vat_minor'    => $sumVat,
            'total_gross_minor'  => $sumNet + $sumVat,
            'derived_from_lines' => true,
        ];
        $warnings = is_array($parsed['warnings'] ?? null) ? $parsed['warnings'] : [];
        $warnings[] = 'Sumy P_13/P_15 w nagłówku puste — policzone z pozycji FaWiersz.';
        $parsed['warnings'] = $warnings;

        return $parsed;
    }

    /**
     * Gate workflow zakupów (draft vs error). Wymaga parse() z success=true (w tym enrich sum).
     * Nie powiela walidacji strukturalnej parse() ani SKU z accept.
     *
     * @param array<string,mixed> $parsed
     * @return array{procurement_ok:bool, level:string, messages:list<string>}
     */
    public function assessProcurementQuality(array $parsed): array
    {
        $invoiceType = strtoupper(trim((string) ($parsed['invoice']['invoice_type'] ?? '')));

        /** Rodzaje poza zakupem towarów — trafiają do status=error zamiast „pustego” draft. */
        $nonProcurementTypes = [
            'KOR', 'KOR_ZAL', 'KOR_ROZ', 'KOR_POD', 'KOR_ODS',
            'ROZ', 'UPR', 'KOR_UPR', 'NOT', 'KOR_NOT',
        ];
        if ($invoiceType !== '' && in_array($invoiceType, $nonProcurementTypes, true)) {
            return [
                'procurement_ok' => false,
                'level'          => 'reject',
                'messages'       => [
                    "Rodzaj faktury „{$invoiceType}” — dokument korygujący/rozliczeniowy (nie zakup na magazyn).",
                ],
            ];
        }

        $messages = [];
        $lines = is_array($parsed['lines'] ?? null) ? $parsed['lines'] : [];
        if (self::countMeaningfulLines($lines) === 0) {
            $messages[] = 'Brak pozycji FaWiersz z nazwą, ilością i kwotą — nie można przyjąć na magazyn.';
        }
        if (self::headerTotalsAreZero($parsed)) {
            $messages[] = 'Suma brutto i netto wynosi 0 — sprawdź XML (Audyt XML) lub odrzuć wpis.';
        }

        if ($messages !== []) {
            return ['procurement_ok' => false, 'level' => 'reject', 'messages' => $messages];
        }

        return [
            'procurement_ok' => true,
            'level'          => 'ok',
            'messages'       => is_array($parsed['warnings'] ?? null) ? $parsed['warnings'] : [],
        ];
    }

    /**
     * Netto linii w groszach (P_11 lub qty × P_9A) — wspólne dla enrich i assess.
     *
     * @param array<string,mixed> $line
     */
    public static function lineNetMinor(array $line): int
    {
        $lineNet = (int) ($line['line_net_minor'] ?? 0);
        if ($lineNet > 0) {
            return $lineNet;
        }
        $qty = (float) ($line['qty'] ?? 0);
        $unitNet = (float) ($line['unit_net'] ?? 0);
        if ($qty > 0 && $unitNet > 0) {
            return (int) round($qty * $unitNet * 100);
        }

        return 0;
    }

    /**
     * @param list<array<string,mixed>> $lines
     */
    public static function countMeaningfulLines(array $lines): int
    {
        $n = 0;
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $hasName = trim((string) ($line['external_name'] ?? '')) !== '';
            $hasQty = (float) ($line['qty'] ?? 0) > 0;
            $hasMoney = self::lineNetMinor($line) > 0;
            if ($hasName && ($hasQty || $hasMoney)) {
                $n++;
            }
        }

        return $n;
    }

    /**
     * @param array<string,mixed> $parsed
     */
    public static function headerTotalsAreZero(array $parsed): bool
    {
        $totals = is_array($parsed['totals'] ?? null) ? $parsed['totals'] : [];

        return ((int) ($totals['total_gross_minor'] ?? 0)) === 0
            && ((int) ($totals['total_net_minor'] ?? 0)) === 0;
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

    /**
     * Pierwszy element o podanej **lokalnej** nazwie: bezpośrednie dzieci, potem XPath (namespace-agnostic).
     */
    private static function findFirstElement(\SimpleXMLElement $root, string $localName): ?\SimpleXMLElement
    {
        foreach ($root->children() as $child) {
            if ($child->getName() === $localName) {
                return $child;
            }
        }
        $direct = $root->xpath('./*[local-name()="' . $localName . '"]');
        if ($direct !== false && isset($direct[0]) && $direct[0] instanceof \SimpleXMLElement) {
            return $direct[0];
        }
        $xp = './/*[local-name()="' . $localName . '"]';
        $found = $root->xpath($xp);

        return ($found !== false && isset($found[0]) && $found[0] instanceof \SimpleXMLElement)
            ? $found[0]
            : null;
    }

    private function parseHeaderFromDoc(\SimpleXMLElement $doc): array
    {
        $h = self::findFirstElement($doc, 'Naglowek');
        if ($h === null) {
            return [];
        }
        return [
            'kod_formularza'   => (string) ($h->KodFormularza ?? ''),
            'kod_systemowy'    => isset($h->KodFormularza) ? (string) ($h->KodFormularza['kodSystemowy'] ?? '') : '',
            'wariant'          => isset($h->KodFormularza) ? (string) ($h->KodFormularza['wariantFormularza'] ?? '') : '',
            'data_wytworzenia' => (string) ($h->DataWytworzeniaFa ?? ''),
            'system_info'      => (string) ($h->SystemInfo ?? ''),
        ];
    }

    private function parsePodmiotElement(?\SimpleXMLElement $p): array
    {
        if ($p === null) {
            return [];
        }

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

    private function parseInvoiceFromFa(\SimpleXMLElement $fa): array
    {
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

    private function parseLinesFromFa(\SimpleXMLElement $fa): array
    {
        /** @var list<\SimpleXMLElement> $lineNodes */
        $lineNodes = [];
        foreach ($fa->FaWiersz as $row) {
            $lineNodes[] = $row;
        }
        if ($lineNodes === []) {
            $alt = $fa->xpath('.//*[local-name()="FaWiersz"]');
            if ($alt !== false) {
                foreach ($alt as $row) {
                    if ($row instanceof \SimpleXMLElement) {
                        $lineNodes[] = $row;
                    }
                }
            }
        }

        $lines = [];
        $idx = 0;
        foreach ($lineNodes as $row) {
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

    private function parseTotalsFromFa(\SimpleXMLElement $fa): array
    {
        // FA(2): P_13_1…P_13_7; FA(3): dodatkowo P_13_6_1, P_13_8, P_13_10 itd. — sumujemy po local-name().
        $totalNet = 0.0;
        // Tylko bezpośrednie dzieci Fa — unikamy podwójnego zliczania z zagnieżdżeń / innych węzłów.
        $netNodes = $fa->xpath('./*[starts-with(local-name(), "P_13_")]');
        if (is_array($netNodes)) {
            foreach ($netNodes as $node) {
                if (!$node instanceof \SimpleXMLElement) {
                    continue;
                }
                $name = $node->getName();
                if (preg_match('/^P_13_\d/', $name) === 1) {
                    $totalNet += $this->parseDecimal((string) $node);
                }
            }
        }
        if ($totalNet <= 0.0) {
            for ($i = 1; $i <= 7; $i++) {
                $totalNet += $this->parseDecimal((string) ($fa->{"P_13_{$i}"} ?? '0'));
            }
        }

        $totalVat = 0.0;
        $vatNodes = $fa->xpath('./*[starts-with(local-name(), "P_14_") and not(contains(local-name(), "W"))]');
        if (is_array($vatNodes)) {
            foreach ($vatNodes as $node) {
                if (!$node instanceof \SimpleXMLElement) {
                    continue;
                }
                $name = $node->getName();
                if (preg_match('/^P_14_\d+$/', $name) === 1) {
                    $totalVat += $this->parseDecimal((string) $node);
                }
            }
        }
        if ($totalVat <= 0.0) {
            for ($i = 1; $i <= 6; $i++) {
                $totalVat += $this->parseDecimal((string) ($fa->{"P_14_{$i}"} ?? '0'));
            }
        }

        $totalGross = $this->parseDecimal((string) ($fa->P_15 ?? '0'));
        if ($totalGross <= 0.0) {
            $p15 = $fa->xpath('./*[local-name()="P_15"]');
            if (is_array($p15) && isset($p15[0])) {
                $totalGross = $this->parseDecimal((string) $p15[0]);
            }
        }

        if ($totalGross <= 0.0 && $totalNet > 0.0) {
            $totalGross = $totalNet + $totalVat;
        }

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
