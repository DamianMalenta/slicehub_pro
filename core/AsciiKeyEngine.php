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

    private static function assertSqlIdentifier(string $name): string
    {
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/', $name)) {
            throw new \InvalidArgumentException('Invalid table or column identifier.');
        }

        return '`' . str_replace('`', '', $name) . '`';
    }
}
