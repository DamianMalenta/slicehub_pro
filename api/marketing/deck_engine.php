<?php
declare(strict_types=1);

/**
 * SliceHub Marketing — Print Deck (Wachlarz A5)
 *
 * Akcje:
 *   deck_list | deck_get | deck_create | deck_save | deck_delete
 *   card_upsert | card_delete | card_reorder
 *   menu_search | deck_seed_forno
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

require_once __DIR__ . '/../../core/db_config.php';
require_once __DIR__ . '/../../core/auth_guard.php';

$raw = file_get_contents('php://input');
$input = json_decode($raw ?: '{}', true) ?? [];
$action = trim((string)($input['action'] ?? ''));

function deckOk($data = null, ?string $msg = null): void
{
    echo json_encode(['success' => true, 'data' => $data, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function deckFail(string $msg, int $code = 400): void
{
    http_response_code($code);
    echo json_encode(['success' => false, 'data' => null, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function deckAllowedTypes(): array
{
    return ['cover', 'hero_duo', 'hero_sizes', 'hero_list', 'cta'];
}

function deckSlugKey(string $s): string
{
    $s = strtolower(trim($s));
    $s = preg_replace('/[^a-z0-9]+/', '-', $s) ?? 'card';
    $s = trim($s, '-');
    return $s !== '' ? substr($s, 0, 64) : 'card-' . substr(bin2hex(random_bytes(3)), 0, 6);
}

function deckLoad(?int $deckId, int $tenantId, PDO $pdo): ?array
{
    if (!$deckId) {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT id, tenant_id, ascii_key, name, status, brand, notes, updated_at
         FROM sh_print_decks
         WHERE id = :id AND tenant_id = :tid AND is_deleted = 0
         LIMIT 1'
    );
    $st->execute([':id' => $deckId, ':tid' => $tenantId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function deckCards(int $deckId, int $tenantId, PDO $pdo): array
{
    $st = $pdo->prepare(
        'SELECT id, card_key, card_type, sort_order, payload_json, updated_at
         FROM sh_print_deck_cards
         WHERE deck_id = :did AND tenant_id = :tid AND is_deleted = 0
         ORDER BY sort_order ASC, id ASC'
    );
    $st->execute([':did' => $deckId, ':tid' => $tenantId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) {
        $payload = $r['payload_json'];
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $r['payload'] = is_array($decoded) ? $decoded : [];
        } else {
            $r['payload'] = is_array($payload) ? $payload : [];
        }
        unset($r['payload_json']);
    }
    unset($r);
    return $rows;
}

function deckSeedPath(): string
{
    return dirname(__DIR__, 2) . '/_docs/ulotki/forno-wachlarz/content.json';
}

function deckReadSeed(): array
{
    $path = deckSeedPath();
    if (!is_readable($path)) {
        deckFail('Brak pliku seed content.json (ulotki/forno-wachlarz).', 500);
    }
    $data = json_decode((string)file_get_contents($path), true);
    if (!is_array($data) || empty($data['cards'])) {
        deckFail('Niepoprawny content.json seed.', 500);
    }
    return $data;
}

if ($action === '') {
    deckFail('Brak action.', 400);
}

// ── deck_list ─────────────────────────────────────────────────────────────
if ($action === 'deck_list') {
    $st = $pdo->prepare(
        'SELECT d.id, d.ascii_key, d.name, d.status, d.brand, d.updated_at,
                (SELECT COUNT(*) FROM sh_print_deck_cards c
                 WHERE c.deck_id = d.id AND c.tenant_id = d.tenant_id AND c.is_deleted = 0) AS card_count
         FROM sh_print_decks d
         WHERE d.tenant_id = :tid AND d.is_deleted = 0
         ORDER BY d.updated_at DESC'
    );
    $st->execute([':tid' => $tenant_id]);
    deckOk(['decks' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── deck_get ──────────────────────────────────────────────────────────────
if ($action === 'deck_get') {
    $deckId = (int)($input['deck_id'] ?? 0);
    $deck = deckLoad($deckId, (int)$tenant_id, $pdo);
    if (!$deck) {
        deckFail('Deck nie znaleziony.', 404);
    }
    deckOk(['deck' => $deck, 'cards' => deckCards($deckId, (int)$tenant_id, $pdo)]);
}

// ── deck_create ───────────────────────────────────────────────────────────
if ($action === 'deck_create') {
    $name = trim((string)($input['name'] ?? 'Nowy wachlarz'));
    $ascii = trim((string)($input['ascii_key'] ?? ''));
    if ($ascii === '') {
        $ascii = 'DECK_' . strtoupper(substr(bin2hex(random_bytes(4)), 0, 8));
    }
    $ascii = strtoupper(preg_replace('/[^A-Z0-9_]/', '_', $ascii) ?? $ascii);
    $brand = trim((string)($input['brand'] ?? 'FORNO PIZZA'));
    $status = trim((string)($input['status'] ?? 'draft'));
    if (!in_array($status, ['draft', 'ready', 'archived'], true)) {
        $status = 'draft';
    }

    try {
        $st = $pdo->prepare(
            'INSERT INTO sh_print_decks (tenant_id, ascii_key, name, status, brand)
             VALUES (:tid, :k, :n, :s, :b)'
        );
        $st->execute([
            ':tid' => $tenant_id,
            ':k' => $ascii,
            ':n' => $name,
            ':s' => $status,
            ':b' => $brand !== '' ? $brand : null,
        ]);
        $id = (int)$pdo->lastInsertId();
        deckOk(['deck' => deckLoad($id, (int)$tenant_id, $pdo), 'cards' => []], 'Utworzono deck.');
    } catch (Throwable $e) {
        deckFail('Nie udało się utworzyć decku: ' . $e->getMessage(), 500);
    }
}

// ── deck_save ─────────────────────────────────────────────────────────────
if ($action === 'deck_save') {
    $deckId = (int)($input['deck_id'] ?? 0);
    $deck = deckLoad($deckId, (int)$tenant_id, $pdo);
    if (!$deck) {
        deckFail('Deck nie znaleziony.', 404);
    }
    $name = trim((string)($input['name'] ?? $deck['name']));
    $brand = array_key_exists('brand', $input) ? trim((string)$input['brand']) : $deck['brand'];
    $status = trim((string)($input['status'] ?? $deck['status']));
    $notes = array_key_exists('notes', $input) ? trim((string)$input['notes']) : null;
    if (!in_array($status, ['draft', 'ready', 'archived'], true)) {
        $status = $deck['status'];
    }
    $st = $pdo->prepare(
        'UPDATE sh_print_decks
         SET name = :n, brand = :b, status = :s, notes = COALESCE(:notes, notes)
         WHERE id = :id AND tenant_id = :tid AND is_deleted = 0'
    );
    $st->execute([
        ':n' => $name,
        ':b' => $brand,
        ':s' => $status,
        ':notes' => $notes,
        ':id' => $deckId,
        ':tid' => $tenant_id,
    ]);
    deckOk(['deck' => deckLoad($deckId, (int)$tenant_id, $pdo)], 'Zapisano.');
}

// ── deck_delete ───────────────────────────────────────────────────────────
if ($action === 'deck_delete') {
    $deckId = (int)($input['deck_id'] ?? 0);
    $deck = deckLoad($deckId, (int)$tenant_id, $pdo);
    if (!$deck) {
        deckFail('Deck nie znaleziony.', 404);
    }
    $st = $pdo->prepare(
        'UPDATE sh_print_decks SET is_deleted = 1 WHERE id = :id AND tenant_id = :tid'
    );
    $st->execute([':id' => $deckId, ':tid' => $tenant_id]);
    deckOk(null, 'Deck usunięty.');
}

// ── card_upsert ───────────────────────────────────────────────────────────
if ($action === 'card_upsert') {
    $deckId = (int)($input['deck_id'] ?? 0);
    $deck = deckLoad($deckId, (int)$tenant_id, $pdo);
    if (!$deck) {
        deckFail('Deck nie znaleziony.', 404);
    }

    $cardId = (int)($input['card_id'] ?? 0);
    $cardType = trim((string)($input['card_type'] ?? 'hero_duo'));
    if (!in_array($cardType, deckAllowedTypes(), true)) {
        deckFail('Nieznany card_type.');
    }
    $cardKey = trim((string)($input['card_key'] ?? ''));
    $payload = $input['payload'] ?? null;
    if (!is_array($payload)) {
        deckFail('payload musi być obiektem.');
    }
    $sortOrder = isset($input['sort_order']) ? (int)$input['sort_order'] : null;

    if ($cardId > 0) {
        $chk = $pdo->prepare(
            'SELECT id, card_key, sort_order FROM sh_print_deck_cards
             WHERE id = :id AND deck_id = :did AND tenant_id = :tid AND is_deleted = 0 LIMIT 1'
        );
        $chk->execute([':id' => $cardId, ':did' => $deckId, ':tid' => $tenant_id]);
        $existing = $chk->fetch(PDO::FETCH_ASSOC);
        if (!$existing) {
            deckFail('Karta nie znaleziona.', 404);
        }
        if ($cardKey === '') {
            $cardKey = $existing['card_key'];
        }
        if ($sortOrder === null) {
            $sortOrder = (int)$existing['sort_order'];
        }
        $st = $pdo->prepare(
            'UPDATE sh_print_deck_cards
             SET card_key = :k, card_type = :t, sort_order = :o, payload_json = :p
             WHERE id = :id AND tenant_id = :tid'
        );
        $st->execute([
            ':k' => $cardKey,
            ':t' => $cardType,
            ':o' => $sortOrder,
            ':p' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ':id' => $cardId,
            ':tid' => $tenant_id,
        ]);
    } else {
        if ($cardKey === '') {
            $cardKey = deckSlugKey((string)($payload['title'] ?? 'card'));
        }
        if ($sortOrder === null) {
            $mx = $pdo->prepare(
                'SELECT COALESCE(MAX(sort_order), -1) + 1 FROM sh_print_deck_cards
                 WHERE deck_id = :did AND tenant_id = :tid AND is_deleted = 0'
            );
            $mx->execute([':did' => $deckId, ':tid' => $tenant_id]);
            $sortOrder = (int)$mx->fetchColumn();
        }
        $st = $pdo->prepare(
            'INSERT INTO sh_print_deck_cards
                (tenant_id, deck_id, card_key, card_type, sort_order, payload_json)
             VALUES (:tid, :did, :k, :t, :o, :p)'
        );
        try {
            $st->execute([
                ':tid' => $tenant_id,
                ':did' => $deckId,
                ':k' => $cardKey,
                ':t' => $cardType,
                ':o' => $sortOrder,
                ':p' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
            $cardId = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            deckFail('Nie udało się dodać karty (klucz?): ' . $e->getMessage(), 500);
        }
    }

    $pdo->prepare('UPDATE sh_print_decks SET updated_at = NOW() WHERE id = :id AND tenant_id = :tid')
        ->execute([':id' => $deckId, ':tid' => $tenant_id]);

    deckOk(['cards' => deckCards($deckId, (int)$tenant_id, $pdo), 'card_id' => $cardId], 'Karta zapisana.');
}

// ── card_delete ───────────────────────────────────────────────────────────
if ($action === 'card_delete') {
    $deckId = (int)($input['deck_id'] ?? 0);
    $cardId = (int)($input['card_id'] ?? 0);
    $deck = deckLoad($deckId, (int)$tenant_id, $pdo);
    if (!$deck) {
        deckFail('Deck nie znaleziony.', 404);
    }
    $st = $pdo->prepare(
        'UPDATE sh_print_deck_cards SET is_deleted = 1
         WHERE id = :id AND deck_id = :did AND tenant_id = :tid'
    );
    $st->execute([':id' => $cardId, ':did' => $deckId, ':tid' => $tenant_id]);
    if ($st->rowCount() < 1) {
        deckFail('Karta nie znaleziona.', 404);
    }
    deckOk(['cards' => deckCards($deckId, (int)$tenant_id, $pdo)], 'Karta usunięta.');
}

// ── card_reorder ──────────────────────────────────────────────────────────
if ($action === 'card_reorder') {
    $deckId = (int)($input['deck_id'] ?? 0);
    $order = $input['order'] ?? null;
    $deck = deckLoad($deckId, (int)$tenant_id, $pdo);
    if (!$deck) {
        deckFail('Deck nie znaleziony.', 404);
    }
    if (!is_array($order) || !$order) {
        deckFail('order musi być tablicą card_id.');
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'UPDATE sh_print_deck_cards SET sort_order = :o
             WHERE id = :id AND deck_id = :did AND tenant_id = :tid AND is_deleted = 0'
        );
        $i = 0;
        foreach ($order as $cid) {
            $st->execute([
                ':o' => $i++,
                ':id' => (int)$cid,
                ':did' => $deckId,
                ':tid' => $tenant_id,
            ]);
        }
        $pdo->prepare('UPDATE sh_print_decks SET updated_at = NOW() WHERE id = :id AND tenant_id = :tid')
            ->execute([':id' => $deckId, ':tid' => $tenant_id]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        deckFail($e->getMessage(), 500);
    }
    deckOk(['cards' => deckCards($deckId, (int)$tenant_id, $pdo)], 'Kolejność zapisana.');
}

// ── menu_search ───────────────────────────────────────────────────────────
if ($action === 'menu_search') {
    $q = trim((string)($input['q'] ?? ''));
    if (mb_strlen($q) < 2) {
        deckOk(['items' => []]);
    }
    $st = $pdo->prepare(
        "SELECT mi.ascii_key, mi.name, mi.description, mi.is_variant_parent,
                (SELECT pt.price FROM sh_price_tiers pt
                 WHERE pt.tenant_id = mi.tenant_id AND pt.target_type = 'ITEM'
                   AND pt.target_sku = mi.ascii_key AND pt.channel = 'POS'
                 LIMIT 1) AS price_pos
         FROM sh_menu_items mi
         WHERE mi.tenant_id = :tid
           AND mi.is_active = 1
           AND (mi.publication_status IS NULL OR mi.publication_status IN ('Live','live','Published'))
           AND (mi.name LIKE :q OR mi.ascii_key LIKE :q2)
         ORDER BY mi.is_variant_parent DESC, mi.name ASC
         LIMIT 25"
    );
    $like = '%' . $q . '%';
    $st->execute([':tid' => $tenant_id, ':q' => $like, ':q2' => $like]);
    deckOk(['items' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

// ── deck_seed_forno ───────────────────────────────────────────────────────
if ($action === 'deck_seed_forno') {
    $seed = deckReadSeed();
    $meta = $seed['deck'] ?? [];
    $ascii = strtoupper(trim((string)($meta['ascii_key'] ?? 'FORNO_WACHLARZ_V1')));
    $force = !empty($input['force']);

    $existing = $pdo->prepare(
        'SELECT id FROM sh_print_decks
         WHERE tenant_id = :tid AND ascii_key = :k AND is_deleted = 0 LIMIT 1'
    );
    $existing->execute([':tid' => $tenant_id, ':k' => $ascii]);
    $exId = (int)$existing->fetchColumn();

    if ($exId && !$force) {
        deckOk([
            'deck' => deckLoad($exId, (int)$tenant_id, $pdo),
            'cards' => deckCards($exId, (int)$tenant_id, $pdo),
            'seeded' => false,
        ], 'Deck już istnieje — użyj force=1 aby nadpisać karty.');
    }

    $pdo->beginTransaction();
    try {
        if ($exId && $force) {
            $pdo->prepare(
                'UPDATE sh_print_deck_cards SET is_deleted = 1
                 WHERE deck_id = :did AND tenant_id = :tid'
            )->execute([':did' => $exId, ':tid' => $tenant_id]);
            $deckId = $exId;
            $pdo->prepare(
                'UPDATE sh_print_decks
                 SET name = :n, brand = :b, status = :s, updated_at = NOW()
                 WHERE id = :id AND tenant_id = :tid'
            )->execute([
                ':n' => (string)($meta['name'] ?? 'Forno Wachlarz'),
                ':b' => (string)($meta['brand'] ?? 'FORNO PIZZA'),
                ':s' => (string)($meta['status'] ?? 'draft'),
                ':id' => $deckId,
                ':tid' => $tenant_id,
            ]);
        } elseif ($exId) {
            $deckId = $exId;
        } else {
            $ins = $pdo->prepare(
                'INSERT INTO sh_print_decks (tenant_id, ascii_key, name, status, brand)
                 VALUES (:tid, :k, :n, :s, :b)'
            );
            $ins->execute([
                ':tid' => $tenant_id,
                ':k' => $ascii,
                ':n' => (string)($meta['name'] ?? 'Forno Wachlarz'),
                ':s' => (string)($meta['status'] ?? 'draft'),
                ':b' => (string)($meta['brand'] ?? 'FORNO PIZZA'),
            ]);
            $deckId = (int)$pdo->lastInsertId();
        }

        $insCard = $pdo->prepare(
            'INSERT INTO sh_print_deck_cards
                (tenant_id, deck_id, card_key, card_type, sort_order, payload_json)
             VALUES (:tid, :did, :k, :t, :o, :p)
             ON DUPLICATE KEY UPDATE
                card_type = VALUES(card_type),
                sort_order = VALUES(sort_order),
                payload_json = VALUES(payload_json),
                is_deleted = 0,
                updated_at = NOW()'
        );

        foreach ($seed['cards'] as $card) {
            $payload = $card['payload'] ?? [];
            if (!is_array($payload)) {
                continue;
            }
            $ctype = (string)($card['card_type'] ?? 'hero_duo');
            if (!in_array($ctype, deckAllowedTypes(), true)) {
                continue;
            }
            $insCard->execute([
                ':tid' => $tenant_id,
                ':did' => $deckId,
                ':k' => (string)($card['card_key'] ?? deckSlugKey((string)($payload['title'] ?? 'card'))),
                ':t' => $ctype,
                ':o' => (int)($card['sort_order'] ?? 0),
                ':p' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        deckFail('Seed failed: ' . $e->getMessage(), 500);
    }

    deckOk([
        'deck' => deckLoad($deckId, (int)$tenant_id, $pdo),
        'cards' => deckCards($deckId, (int)$tenant_id, $pdo),
        'seeded' => true,
    ], 'Zaseedowano wachlarz Forno.');
}

deckFail('Nieznana akcja: ' . $action, 400);
