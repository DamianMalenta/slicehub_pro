<?php

declare(strict_types=1);

/**
 * Money — SSOT arytmetyki pieniądza w SliceHub (jednostki minor = grosze).
 *
 * KONTRAKT WARSTW (§KONSTYTUCJA — świętość pieniądza):
 *   - Domena (`PayrollLedger`, `AdvanceEngine`, `MealEngine`, workery) operuje
 *     WYŁĄCZNIE na `int` groszach. Nigdy nie parsuje wejścia użytkownika.
 *   - Transport (routery API) zamienia to, co przyszło z HTTP, na `int` grosze
 *     przez tę klasę — i tylko tu wolno zobaczyć `float`.
 *
 * Klasa jest CZYSTA: brak PDO, brak `exit`, brak nagłówków. Dzięki temu da się
 * ją odpalić z CLI w testach.
 */
final class Money
{
    /**
     * Sanity cap jednej kwoty: 1 000 000,00 PLN w groszach.
     *
     * SSOT limitu — egzekwuje go `PayrollLedger::record()` (jedyna brama zapisu
     * do ledgera), a `AdvanceEngine` tylko go re-eksportuje. Chroni przed
     * literówką typu "1000000" wpisaną w pole PLN, która trafiłaby do
     * append-only księgi i wymagała reversala.
     */
    public const MAX_MINOR = 100_000_000;

    /**
     * PLN (liczba lub string, np. "12.50", "-3,40") → int grosze, HALF_UP
     * (zaokrąglenie od zera, tak jak dotychczasowe `round()`).
     *
     * Podwójne zaokrąglenie (najpierw do 4 miejsc) usuwa artefakt IEEE 754:
     * 12.345 * 100 = 1234.4999999999998, co samo `round()` ścinało do 1234.
     *
     * @param int|float|string $pln
     *
     * @throws \InvalidArgumentException gdy wejście nie jest liczbą
     */
    public static function fromPln($pln): int
    {
        if (is_string($pln)) {
            $pln = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], trim($pln));
        }
        if (!is_int($pln) && !is_float($pln) && !(is_string($pln) && is_numeric($pln))) {
            throw new \InvalidArgumentException('Amount must be numeric (PLN).');
        }
        if (is_float($pln) && !is_finite($pln)) {
            throw new \InvalidArgumentException('Amount must be a finite number.');
        }

        $scaled = round(((float)$pln) * 100.0, 4);

        return (int)($scaled >= 0.0 ? floor($scaled + 0.5) : ceil($scaled - 0.5));
    }

    /**
     * int grosze → string PLN z kropką i dwoma miejscami ("-12.05").
     * Używane tam, gdzie kolumna jest DECIMAL (np. `sh_meals.employee_price`).
     */
    public static function formatMinor(int $minor): string
    {
        $sign  = $minor < 0 ? '-' : '';
        $abs   = abs($minor);

        return sprintf('%s%d.%02d', $sign, intdiv($abs, 100), $abs % 100);
    }

    /** Czy kwota mieści się w sanity capie (wartość bezwzględna). */
    public static function isWithinCap(int $minor): bool
    {
        return abs($minor) <= self::MAX_MINOR;
    }
}
