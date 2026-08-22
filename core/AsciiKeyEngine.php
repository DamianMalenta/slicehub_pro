<?php

declare(strict_types=1);

/**
 * Section 29 — ASCII key generation for menu / modifier identifiers.
 */
final class AsciiKeyEngine
{
    /**
     * @return array{
     *   input_name: string,
     *   generated_key: string,
     *   collision_check: string,
     *   final_key: string
     * }
     */
    public static function generate(
        PDO $pdo,
        int $tenantId,
        string $inputName,
        string $table = 'sh_menu_items',
        string $column = 'ascii_key'
    ): array {
        if ($tenantId <= 0) {
            throw new \InvalidArgumentException('tenant_id must be positive.');
        }

        $tableEsc  = self::assertSqlIdentifier($table);
        $columnEsc = self::assertSqlIdentifier($column);

        $map = [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n', 'Ó' => 'o', 'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z',
        ];
        $str = strtr($inputName, $map);
        $str = strtolower($str);
        $str = preg_replace('/[^a-z0-9]+/', '_', $str) ?? '';
        $str = trim($str, '_');
        if ($str === '') {
            $str = 'item';
        }
        $baseKey = $str;

        $finalKey  = $baseKey;
        $counter   = 2;
        $collision = 'unique';

        $tablesWithoutTenant = ['sh_modifiers'];
        if (in_array($table, $tablesWithoutTenant, true)) {
            $sql = "SELECT 1 FROM {$tableEsc} t
                    JOIN `sh_modifier_groups` mg ON mg.id = t.group_id
                    WHERE t.{$columnEsc} = :key AND mg.tenant_id = :tid LIMIT 1";
        } else {
            $sql = "SELECT 1 FROM {$tableEsc} WHERE {$columnEsc} = :key AND tenant_id = :tid LIMIT 1";
        }
        $stmt = $pdo->prepare($sql);

        while (true) {
            $stmt->execute([':key' => $finalKey, ':tid' => $tenantId]);
            if ($stmt->fetchColumn() === false) {
                break;
            }
            $collision = 'exists';
            $finalKey    = $baseKey . '_' . $counter;
            $counter++;
        }

        return [
            'input_name'      => $inputName,
            'generated_key'   => $baseKey,
            'collision_check' => $collision,
            'final_key'       => $finalKey,
        ];
    }

    /**
     * Pure transliteration of an arbitrary string into a safe ASCII key segment.
     *
     * Unlike generate(), this does NOT touch the database and does NOT perform
     * collision detection — it is the canonical replacement for inline
     * preg_replace('/[^A-Za-z0-9_]/', ...) calls that previously STRIPPED Polish
     * characters (e.g. "PIZZA_ŁOSOŚ" → "PIZZA_OS") instead of transliterating
     * them ("PIZZA_ŁOSOŚ" → "PIZZA_LOSOS").
     *
     * Behaviour:
     *   1. Map Polish diacritics to ASCII equivalents (ą→a, ł→l, …, both cases).
     *   2. Collapse every run of non-[A-Za-z0-9_-] (or non-[A-Za-z0-9_] when
     *      $keepDash is false) into a single underscore.
     *   3. Trim leading/trailing underscores (and dashes when $keepDash is true).
     *   4. Preserve original case — callers may strtoupper() if needed.
     *
     * @param string $input    Raw input (user-provided key, name fragment, …).
     * @param bool   $keepDash When true, dashes are preserved in the output
     *                         (useful for item/modifier-group SKUs that allow
     *                         dashes). Defaults to false to match generate().
     * @return string          Transliterated ASCII-safe key segment (may be empty
     *                         if the input contained no mappable characters).
     */
    public static function transliterate(string $input, bool $keepDash = false): string
    {
        $map = [
            'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n', 'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',
            'Ą' => 'a', 'Ć' => 'c', 'Ę' => 'e', 'Ł' => 'l', 'Ń' => 'n', 'Ó' => 'o', 'Ś' => 's', 'Ź' => 'z', 'Ż' => 'z',
        ];
        $str = strtr($input, $map);
        $pattern = $keepDash ? '/[^A-Za-z0-9_-]+/' : '/[^A-Za-z0-9]+/';
        $str = preg_replace($pattern, '_', $str) ?? '';
        $trimChars = $keepDash ? "_-" : '_';
        $str = trim($str, $trimChars);
        return $str;
    }

    private static function assertSqlIdentifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
            throw new \InvalidArgumentException('Invalid table or column identifier.');
        }

        return '`' . str_replace('`', '', $name) . '`';
    }
}
