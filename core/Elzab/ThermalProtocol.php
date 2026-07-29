<?php

declare(strict_types=1);

namespace SliceHub\Elzab;

/**
 * ThermalProtocol — implementacja protokołu Thermal (Posnet-compatible)
 * dla drukarki Elzab Zeta Online.
 *
 * Ramkowanie: ESC P (0x1B 0x50) + data + XOR_CC + ESC \ (0x1B 0x5C)
 * Checksum: XOR wszystkich bajtów danych, wynik jako 2 znaki hex.
 *
 * Komendy:
 *   $h  — start transakcji (paragonu)
 *   $l  — linia paragonu (nazwa, ilość, cena, PTU)
 *   $y  — koniec transakcji z formami płatności
 *   $e  — anulowanie transakcji
 *   #r  — raport dobowy
 *   #e  — ustawienie trybu obsługi błędów
 *   #s  — zapytanie o status i stawki PTU
 *   $d  — otwarcie szuflady
 *
 * Strona kodowa: MAZOVIA (CP790) — polskie znaki diakrytyczne.
 *
 * Referencje:
 *   - Java: bartprokop/fiscal-printers (Thermal101.java)
 *   - PHP: jkobus/posnet-thermal-php (RequestFrame.php)
 *   - Spec: Elzab om_iprg.zip, POT-I-DEV-10-4530
 */
final class ThermalProtocol
{
    /** ESC P — prefix ramki */
    public const PREFIX = "\x1B\x50";

    /** ESC \ — suffix ramki */
    public const SUFFIX = "\x1B\x5C";

    /** DLE — Data Link Escape (status zapytanie) */
    public const DLE = "\x10";

    /** ENQ — Enquiry (status zapytanie) */
    public const ENQ = "\x05";

    /** CAN — Cancel (reset) */
    public const CAN = "\x18";

    /** Separatory wewnątrz komend */
    public const CR = "\r";
    public const SLASH = '/';
    public const SEMI = ';';
    public const TAB = "\t";

    /**
     * Mapowanie stawek VAT na litery PTU w drukarce (A-G).
     * Konfiguracja serwisowa drukarki: A=23%, B=8%, C=5%, D=0%, E=zw.
     */
    public const VAT_TO_PTU = [
        23 => 'A',
        8  => 'B',
        5  => 'C',
        0  => 'D',
        -1 => 'E',  // zwolniony (zw)
    ];

    /**
     * Mapowanie form płatności SliceHub → kody Thermal.
     * 0=gotówka, 1=karta, 2=czek, 3=voucher, 4=inne, 5=kredyt.
     */
    public const PAYMENT_TYPE = [
        'cash'     => 0,
        'card'     => 1,
        'online'   => 1,
        'terminal' => 1,
        'voucher'  => 3,
        'other'    => 4,
    ];

    // ── Ramkowanie ────────────────────────────────────────────────────────

    /**
     * Zbuduj ramkę protokołu Thermal: ESC P + data + XOR_CC + ESC \
     */
    public static function buildFrame(string $data): string
    {
        $cc = self::xorChecksum($data);
        return self::PREFIX . $data . $cc . self::SUFFIX;
    }

    /**
     * XOR checksum — 0xFF XOR wszystkie bajty, wynik jako 2 znaki hex uppercase.
     */
    public static function xorChecksum(string $data): string
    {
        $check = 0xFF;
        $len = strlen($data);
        for ($i = 0; $i < $len; $i++) {
            $check ^= ord($data[$i]);
        }
        return strtoupper(dechex($check & 0xFF));
    }

    /**
     * Parsuj odpowiedź — usuń prefix/suffix, zwróć czyste dane.
     */
    public static function parseResponse(string $raw): string
    {
        $start = strpos($raw, self::PREFIX);
        if ($start !== false) {
            $raw = substr($raw, $start + 2);
        }
        $end = strpos($raw, self::SUFFIX);
        if ($end !== false) {
            $raw = substr($raw, 0, $end);
        }
        // Usuń checksum (ostatnie 2 znaki)
        if (strlen($raw) > 2) {
            $raw = substr($raw, 0, -2);
        }
        return $raw;
    }

    /**
     * Wyciągnij kod błędu z odpowiedzi (przed '#Z').
     */
    public static function extractErrorCode(string $response): int
    {
        if ($response === '') {
            return -1; // timeout
        }
        $pos = strpos($response, '#Z');
        if ($pos === false) {
            return -1;
        }
        $code = substr($response, 0, $pos);
        return (int)$code;
    }

    // ── Komendy ───────────────────────────────────────────────────────────

    /**
     * Ustaw tryb obsługi błędów (0=brak, 1=minimum, 2=średni, 3=pełny software'owy).
     */
    public static function cmdSetErrorMode(int $mode = 3): string
    {
        return self::buildFrame($mode . '#e');
    }

    /**
     * Zapytanie o status drukarki i stawki PTU.
     * param: 23 = pełne info (status + totalizery + stawki PTU + numer seryjny).
     */
    public static function cmdStatusQuery(int $param = 23): string
    {
        return self::buildFrame($param . '#s');
    }

    /**
     * Start transakcji (paragonu fiskalnego).
     * param: 0 = standardowy paragon.
     */
    public static function cmdTransactionStart(int $param = 0): string
    {
        return self::buildFrame($param . '$h');
    }

    /**
     * Linia paragonu fiskalnego.
     *
     * @param int    $lineNo       Numer linii (1-based)
     * @param string $name         Nazwa towaru (max 40 znaków w Mazovia)
     * @param float  $quantity     Ilość (np. 1, 2, 0.5)
     * @param float  $unitPrice    Cena jednostkowa brutto
     * @param string $ptuLetter    Litera PTU (A-G)
     * @param float  $grossTotal   Wartość brutto linii
     * @param int    $discountType 0=brak, 1=kwotowy, 2=procentowy
     * @param float  $discount     Wartość rabatu
     */
    public static function cmdTransactionLine(
        int $lineNo,
        string $name,
        float $quantity,
        float $unitPrice,
        string $ptuLetter,
        float $grossTotal,
        int $discountType = 0,
        float $discount = 0.0
    ): string {
        // Format: lineNo;discountType$l name\r quantity\r ptu/unitPrice/grossTotal/discount/
        $data = $lineNo . self::SEMI . $discountType . '$l'
            . self::toMazovia($name) . self::CR
            . self::formatQty($quantity) . self::CR
            . $ptuLetter . self::SLASH
            . self::formatAmount($unitPrice) . self::SLASH
            . self::formatAmount($grossTotal) . self::SLASH
            . self::formatAmount($discount) . self::SLASH;

        return self::buildFrame($data);
    }

    /**
     * Koniec transakcji z formami płatności.
     *
     * @param float       $total      Kwota całkowita brutto
     * @param string      $cashbox    Identyfikator kasy (np. "POS1")
     * @param string      $cashier    Identyfikator kasjera (np. "kelner1")
     * @param string      $reference  Numer referencyjny (np. numer zamówienia)
     * @param array       $payments   Lista płatności: [['type'=>0, 'amount'=>50.00, 'name'=>'Gotówka'], ...]
     * @param string|null $footLine1  Dodatkowa linia stopki 1
     * @param string|null $footLine2  Dodatkowa linia stopki 2
     * @param string|null $footLine3  Dodatkowa linia stopki 3
     * @param float       $discount   Kwota rabatu kwotowego (0 = brak)
     */
    public static function cmdTransactionEnd(
        float $total,
        string $cashbox,
        string $cashier,
        string $reference,
        array $payments = [],
        ?string $footLine1 = null,
        ?string $footLine2 = null,
        ?string $footLine3 = null,
        float $discount = 0.0
    ): string {
        $cash = 0.0;
        $noPayments = 0;
        $pfx = '';
        $pfn = '';
        $pfa = '';

        foreach ($payments as $pay) {
            $type = (int)($pay['type'] ?? 0);
            $amount = (float)($pay['amount'] ?? 0);
            $name = (string)($pay['name'] ?? '');

            if ($type === 0) {
                // Gotówka — specjalna obsługa (wpłata)
                $cash = $amount;
                continue;
            }
            $noPayments++;
            $pfx .= $type . self::SEMI;
            $pfn .= self::toMazovia($name) . self::CR;
            $pfa .= self::formatAmount($amount) . self::SLASH;
        }

        $data = '3;'          // 3 dodatkowe linie stopki
            . '0;'            // zachowanie standardowe (drukuj + zakończ)
            . '1;'            // skrócone podsumowanie jeśli możliwe
            . '0;'            // kwota DSP ujemna = 0
            . ($discount > 0 ? '1;' : '0;')  // rodzaj rabatu: 1=kwotowy
            . '0;'            // blok KAUCJA_POBRANA = brak
            . '0;'            // blok KAUCJA_ZWROCONA = brak
            . '1;'            // występuje string numer_systemowy
            . $noPayments . ';' // liczba dodatkowych form płatności
            . '0;'            // kwota RESZTA ignorowana
            . ($cash != 0.0 ? '1;' : '0;')  // kwota WPŁATA
            . $pfx            // formy płatności — typy
            . '$y'            // kod rozkazu
            . self::toMazovia($cashbox) . self::CR
            . self::toMazovia($cashier) . self::CR
            . self::toMazovia($reference) . self::CR
            . self::toMazovia($footLine1 ?? '') . self::CR
            . self::toMazovia($footLine2 ?? '') . self::CR
            . self::toMazovia($footLine3 ?? '') . self::CR
            . $pfn            // nazwy form płatności
            . self::formatAmount($total) . self::SLASH  // total
            . self::formatAmount($total)               // DSP
            . self::formatAmount($discount) . self::SLASH  // RABAT kwotowy
            . self::formatAmount($cash) . self::SLASH  // WPŁATA gotówka
            . $pfa            // formy płatności — kwoty
            . '0/';           // RESZTA

        return self::buildFrame($data);
    }

    /**
     * Anulowanie bieżącej transakcji.
     */
    public static function cmdTransactionCancel(): string
    {
        return self::buildFrame('0$e');
    }

    /**
     * Raport dobowy (zamyka dobę fiskalną).
     */
    public static function cmdDailyReport(): string
    {
        $now = getdate();
        $year = $now['year'] - 2000;
        $month = $now['mon'];
        $day = $now['mday'];
        return self::buildFrame("1;{$year};{$month};{$day}#r");
    }

    /**
     * Otwarcie szuflady kasowej.
     */
    public static function cmdOpenDrawer(): string
    {
        return self::buildFrame('1$d');
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Formatuj kwotę jako string z kropką dziesiętną (2 miejsca).
     */
    public static function formatAmount(float $amount): string
    {
        return number_format($amount, 2, '.', '');
    }

    /**
     * Formatuj ilość — integer jeśli całość, wpp 3 miejsca po przecinku.
     */
    public static function formatQty(float $qty): string
    {
        if ($qty == floor($qty)) {
            return (string)(int)$qty;
        }
        return number_format($qty, 3, '.', '');
    }

    /**
     * Konwersja UTF-8 → MAZOVIA (CP790).
     * Tabela najczęstszych polskich znaków.
     */
    public static function toMazovia(string $text): string
    {
        static $map = null;
        if ($map === null) {
            // MAZOVIA (CP790) — kody polskich znaków
            $map = [
                'ą' => "\xA1", 'ć' => "\xA6", 'ę' => "\xA8", 'ł' => "\xAC",
                'ń' => "\xA5", 'ó' => "\xA2", 'ś' => "\xAA", 'ź' => "\xAD",
                'ż' => "\xAB", 'Ą' => "\xA1", 'Ć' => "\xA6", 'Ę' => "\xA8",
                'Ł' => "\xAC", 'Ń' => "\xA5", 'Ó' => "\xA2", 'Ś' => "\xAA",
                'Ź' => "\x8D", 'Ż' => "\xAB",
            ];
        }
        return strtr($text, $map);
    }

    /**
     * Mapuj stawkę VAT (procent) na literę PTU.
     */
    public static function vatToPtu(float $vatRate): string
    {
        $int = (int)round($vatRate);
        return self::VAT_TO_PTU[$int] ?? 'D'; // default: 0%
    }

    /**
     * Mapuj metodę płatności SliceHub na kod Thermal.
     */
    public static function paymentType(string $method): int
    {
        return self::PAYMENT_TYPE[$method] ?? 0; // default: gotówka
    }

    /**
     * Opisy kodów błędów drukarki.
     */
    public static function errorDescription(int $code): string
    {
        $errors = [
            -1 => 'TIMEOUT — brak odpowiedzi drukarki',
            0  => 'Brak błędu',
            1  => 'Brak inicjalizacji RTC',
            2  => 'Błąd bajtu kontrolnego (CRC)',
            3  => 'Zła ilość parametrów',
            4  => 'Błąd parametru/parametrów',
            5  => 'Błąd operacji z RTC',
            6  => 'Błąd operacji z modułem fiskalnym',
            7  => 'Błąd daty',
            8  => 'Niezerowe totalizery',
            9  => 'Błąd operacji IO',
            10 => 'Zmiana czasu poza dopuszczonym zakresem',
            11 => 'Zła ilość stawek PTU lub błąd liczby',
            12 => 'Błędny nagłówek',
            13 => 'Fiskalizacja urządzenia sfiskalizowanego',
            14 => 'Nagłówek do RAM dla urządzenia sfiskalizowanego',
            16 => 'Błąd pola <nazwa>',
            17 => 'Błąd pola <ilość>',
            18 => 'Błąd pola <cena jednostkowa>',
            19 => 'Błąd pola <wartość brutto>',
            20 => 'Błąd PTU — nieprawidłowa stawka',
            21 => 'Błąd pola <rabat>',
            22 => 'Błąd obliczeń rabatu',
            23 => 'Błąd sumy kontrolnej paragonu',
            24 => 'Błąd pola <forma płatności>',
            25 => 'Błąd obliczeń form płatności',
            26 => 'Błąd pola <wpłata>',
            27 => 'Błąd obliczeń reszty',
            28 => 'Błąd — transakcja już rozpoczęta',
            29 => 'Błąd — brak rozpoczętej transakcji',
            30 => 'Błąd — brak miejsca w bazie towarowej',
            31 => 'Błąd — przekroczony limit linii paragonu',
            32 => 'Błąd — brak papieru',
            33 => 'Błąd — drukarka offline',
            34 => 'Błąd — mechanizm drukujący',
            35 => 'Błąd — bateria RTC słaba',
            36 => 'Błąd — pamięć fiskalna pełna',
            37 => 'Błąd — przekroczony limit paragonów',
        ];
        return $errors[$code] ?? "Nieznany błąd #{$code}";
    }
}
