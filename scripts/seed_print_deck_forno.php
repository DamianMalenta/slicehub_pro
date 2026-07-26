<?php
/**
 * Seed wachlarza Forno z _docs/ulotki/forno-wachlarz/content.json
 * Usage: php scripts/seed_print_deck_forno.php [tenant_id]
 */
declare(strict_types=1);

require __DIR__ . '/../core/db_config.php';

$tid = isset($argv[1]) ? (int)$argv[1] : 1;
if ($tid < 1) {
    fwrite(STDERR, "tenant_id invalid\n");
    exit(1);
}

$chk = $pdo->prepare('SELECT id FROM sh_tenant WHERE id = ?');
$chk->execute([$tid]);
if (!$chk->fetchColumn()) {
    fwrite(STDERR, "Tenant $tid nie istnieje.\n");
    exit(1);
}

$path = __DIR__ . '/../_docs/ulotki/forno-wachlarz/content.json';
$seed = json_decode((string)file_get_contents($path), true);
if (!is_array($seed) || empty($seed['cards'])) {
    fwrite(STDERR, "Bad content.json\n");
    exit(1);
}

$meta = $seed['deck'] ?? [];
$ascii = (string)($meta['ascii_key'] ?? 'FORNO_WACHLARZ_V1');

$pdo->beginTransaction();
try {
    $ex = $pdo->prepare('SELECT id FROM sh_print_decks WHERE tenant_id=? AND ascii_key=? AND is_deleted=0');
    $ex->execute([$tid, $ascii]);
    $did = (int)$ex->fetchColumn();

    if ($did) {
        $pdo->prepare('UPDATE sh_print_deck_cards SET is_deleted=1 WHERE deck_id=? AND tenant_id=?')
            ->execute([$did, $tid]);
        $pdo->prepare('UPDATE sh_print_decks SET name=?, brand=?, status=?, updated_at=NOW() WHERE id=? AND tenant_id=?')
            ->execute([
                (string)($meta['name'] ?? 'Forno Wachlarz'),
                (string)($meta['brand'] ?? 'FORNO PIZZA'),
                (string)($meta['status'] ?? 'draft'),
                $did,
                $tid,
            ]);
    } else {
        $pdo->prepare('INSERT INTO sh_print_decks (tenant_id, ascii_key, name, status, brand) VALUES (?,?,?,?,?)')
            ->execute([
                $tid,
                $ascii,
                (string)($meta['name'] ?? 'Forno Wachlarz'),
                (string)($meta['status'] ?? 'draft'),
                (string)($meta['brand'] ?? 'FORNO PIZZA'),
            ]);
        $did = (int)$pdo->lastInsertId();
    }

    $ins = $pdo->prepare(
        'INSERT INTO sh_print_deck_cards (tenant_id, deck_id, card_key, card_type, sort_order, payload_json)
         VALUES (?,?,?,?,?,?)
         ON DUPLICATE KEY UPDATE card_type=VALUES(card_type), sort_order=VALUES(sort_order),
           payload_json=VALUES(payload_json), is_deleted=0, updated_at=NOW()'
    );

    foreach ($seed['cards'] as $c) {
        $ins->execute([
            $tid,
            $did,
            (string)$c['card_key'],
            (string)$c['card_type'],
            (int)$c['sort_order'],
            json_encode($c['payload'], JSON_UNESCAPED_UNICODE),
        ]);
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

echo "OK tenant=$tid deck_id=$did cards=" . count($seed['cards']) . PHP_EOL;
