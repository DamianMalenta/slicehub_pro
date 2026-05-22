<?php
declare(strict_types=1);

/**
 * Stosuje UPDATE aliasów z migracji 004 (AutoScan) — bez ALTER (wymaga chain).
 */
function seed_apply_search_aliases(PDO $pdo): int
{
    $file = dirname(__DIR__, 2) . '/database/migrations/004_expand_search_aliases.sql';
    if (!is_file($file)) {
        throw new RuntimeException('Brak pliku database/migrations/004_expand_search_aliases.sql');
    }
    $body = file_get_contents($file);
    if ($body === false) {
        throw new RuntimeException('Nie można odczytać 004_expand_search_aliases.sql');
    }
    $n = 0;
    if (preg_match_all('/UPDATE\s+sys_items\s+SET[^;]+;/si', $body, $matches)) {
        foreach ($matches[0] as $stmt) {
            $pdo->exec($stmt);
            $n++;
        }
    }
    return $n;
}
