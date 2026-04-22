<?php
declare(strict_types=1);

/**
 * TemplateRenderer — Mustache-lite renderer dla szablonów powiadomień.
 *
 * Obsługuje:
 *   {{var}}             — zwykła zamiana (escape HTML dla email, raw dla SMS)
 *   {{var|minutes}}     — dzieli sekundy na minuty (dla etaSeconds)
 *   {{var|grosze_pln}}  — formatuje grosze jako "X,XX zł"
 *   {{var|date_pl}}     — formatuje datetime jako "D. M YYYY HH:MM"
 *
 * Bezpieczeństwo: tylko zmienne z whitelist $allowed są zamieniane.
 * Nieznane {{zmienne}} są usuwane (nie przepuszczane).
 */
final class TemplateRenderer
{
    /** Whitelist zmiennych dostępnych w szablonach. */
    private const ALLOWED_VARS = [
        'customer_name', 'order_number', 'order_type',
        'eta_minutes', 'eta_seconds', 'tracking_url',
        'store_name', 'store_phone', 'store_email', 'store_address',
        'total_pln', 'total_grosze', 'payment_method',
        'delivery_address', 'promised_time',
        'channel', 'status', 'delivery_status',
    ];

    /**
     * Renderuj szablon zastępując zmienne danymi z kontekstu.
     *
     * @param string $template  Treść szablonu z placeholderami {{var}} lub {{var|filter}}
     * @param array  $ctx       Dane kontekstowe (klucz => wartość)
     * @param bool   $htmlSafe  Jeśli true — escape HTML (dla email). False → raw (dla SMS)
     */
    public static function render(string $template, array $ctx, bool $htmlSafe = false): string
    {
        // Normalizacja kontekstu: snake_case keys, obliczenie pochodnych
        $vars = self::buildVars($ctx, $htmlSafe);

        // Zamień {{var|filter}} i {{var}}
        $rendered = preg_replace_callback(
            '/\{\{([a-z_]+)(?:\|([a-z_]+))?\}\}/',
            function (array $m) use ($vars, $htmlSafe) {
                $varName = $m[1];
                $filter  = $m[2] ?? null;

                if (!in_array($varName, self::ALLOWED_VARS, true)) {
                    return ''; // usuwamy nieznane zmienne
                }

                $raw = $vars[$varName] ?? null;

                if ($filter !== null) {
                    return self::applyFilter($raw, $filter, $htmlSafe);
                }

                $value = ($raw === null || $raw === '') ? '' : (string)$raw;
                return $htmlSafe ? htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $value;
            },
            $template
        );

        return $rendered ?? $template;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private static function buildVars(array $ctx, bool $htmlSafe): array
    {
        $vars = $ctx;

        // Pochodna: eta_minutes z eta_seconds (zaokrąglone w górę)
        if (!isset($vars['eta_minutes']) && isset($vars['eta_seconds'])) {
            $sec = (int)$vars['eta_seconds'];
            $vars['eta_minutes'] = $sec > 0 ? (string)((int)ceil($sec / 60)) : '?';
        }

        // Pochodna: total_pln z total_grosze
        if (!isset($vars['total_pln']) && isset($vars['total_grosze'])) {
            $vars['total_pln'] = number_format((int)$vars['total_grosze'] / 100, 2, ',', ' ') . ' zł';
        }

        return $vars;
    }

    private static function applyFilter(mixed $raw, string $filter, bool $htmlSafe): string
    {
        switch ($filter) {
            case 'minutes':
                $sec = (int)($raw ?? 0);
                return $sec > 0 ? (string)(int)ceil($sec / 60) : '?';

            case 'grosze_pln':
                $g = (int)($raw ?? 0);
                return number_format($g / 100, 2, ',', ' ') . ' zł';

            case 'date_pl':
                if (!$raw) return '—';
                try {
                    $dt = new \DateTime((string)$raw);
                    $months = ['', 'sty', 'lut', 'mar', 'kwi', 'maj', 'cze',
                               'lip', 'sie', 'wrz', 'paź', 'lis', 'gru'];
                    return $dt->format('j') . ' ' . $months[(int)$dt->format('n')]
                         . ' ' . $dt->format('Y H:i');
                } catch (\Throwable $e) {
                    return (string)$raw;
                }

            default:
                $val = ($raw === null) ? '' : (string)$raw;
                return $htmlSafe ? htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $val;
        }
    }
}
