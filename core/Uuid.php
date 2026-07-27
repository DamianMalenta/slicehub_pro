<?php

declare(strict_types=1);

/**
 * Uuid — SSOT generowania identyfikatorów w całym SliceHub.
 *
 * To PRYMITYW infrastrukturalny (jak `Money`), a nie silnik domenowy — dlatego
 * `require_once` tej klasy z dowolnego silosu NIE narusza izolacji z
 * `_docs/18_BACKOFFICE_HR_LOGIC.md §9`. Zakazane jest wciąganie cudzych
 * *Engine*-ów, nie wspólnych prymitywów bezstanowych.
 *
 * Zastąpiła 14 kopii tego samego algorytmu (`uuidV4()` / `uuid4()` /
 * `$generateUuidV4` / `synthUuid()` / `deterministicUuid()` / `legacyLedgerUuid()`)
 * rozsianych po `core/`, `api/` i `scripts/`.
 *
 * UWAGA — ZGODNOŚĆ WSTECZNA:
 * `deterministic()` musi zwracać DOKŁADNIE to samo co poprzednie kopie, bo od
 * tych wartości zależą `entry_uuid` już zapisane w `sh_payroll_ledger`
 * (idempotency append-only księgi). Algorytm jest przepisany bajt w bajt:
 * sha1(seed) → pierwsze 16 bajtów → wersja 5 → wariant RFC 4122.
 * Regresję pilnują golden vectory w `scripts/test_hr_payroll_ledger.php`.
 *
 * Klasa jest CZYSTA (bez PDO, bez HTTP) — testowalna z CLI.
 */
final class Uuid
{
    /** Losowy UUID v4 (nowe agregaty: sesje pracy, wnioski o zaliczkę, wpisy ledgera). */
    public static function v4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);

        return self::format(bin2hex($b));
    }

    /**
     * Deterministyczny UUID (v5-like) z tekstowego seeda — ten sam seed zawsze
     * daje ten sam UUID, co czyni z niego klucz idempotencji dla ledgera.
     *
     * Semantyka seeda (np. `meal-{tenant}-{id}`, `adv-pay-…`) NIE należy tutaj —
     * zostaje w silniku, który ją definiuje. Tu jest wyłącznie hashowanie.
     */
    public static function deterministic(string $seed): string
    {
        $hash  = sha1($seed);
        $bytes = '';
        foreach (str_split(substr($hash, 0, 32), 2) as $hex) {
            $bytes .= chr((int)hexdec($hex));
        }
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x50);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return self::format(bin2hex($bytes));
    }

    public static function isValid(string $uuid): bool
    {
        return (bool)preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i',
            $uuid
        );
    }

    private static function format(string $hex32): string
    {
        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex32, 0, 8),
            substr($hex32, 8, 4),
            substr($hex32, 12, 4),
            substr($hex32, 16, 4),
            substr($hex32, 20, 12)
        );
    }
}
