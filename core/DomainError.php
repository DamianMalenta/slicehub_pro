<?php

declare(strict_types=1);

/**
 * DomainError — rozbicie komunikatu wyjątku domenowego na `code` + `detail`.
 *
 * KONTRAKT SILNIKÓW HR/Payroll: wyjątki niosą komunikat w formacie
 * `ERR_CODE (detal)`, gdzie `ERR_CODE` to ASCII-owa stała klasy
 * (`PERIOD_LOCKED`, `INVALID_AMOUNT`, `VOID_BLOCKED_PARTIAL_REPAYMENT`…).
 *
 * Bez tego rozbicia cały string lądował w polu `code` odpowiedzi API i klient
 * nie miał czego porównywać (`INVALID_PRICE (price_minor must be int > 0)`).
 *
 * Klasa jest CZYSTA — brak PDO/HTTP, testowana z CLI.
 */
final class DomainError
{
    public const FALLBACK_CODE = 'DOMAIN_ERROR';

    /**
     * @return array{0:string, 1:string} [code, detail]
     */
    public static function split(string $message): array
    {
        $raw = trim($message);
        if ($raw === '') {
            return [self::FALLBACK_CODE, 'Domain rule violation.'];
        }

        if (!preg_match('/^([A-Z][A-Z0-9_]{2,})(\s.*)?$/s', $raw, $m)) {
            return [self::FALLBACK_CODE, $raw];
        }

        $code   = $m[1];
        $detail = trim($m[2] ?? '');
        $detail = trim($detail, " \t\n\r\0\x0B");
        if ($detail !== '' && $detail[0] === '(' && substr($detail, -1) === ')') {
            $detail = trim(substr($detail, 1, -1));
        }

        return [$code, $detail !== '' ? $detail : $code];
    }
}
