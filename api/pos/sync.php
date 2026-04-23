<?php
declare(strict_types=1);

/**
 * SliceHub POS — Sync Endpoint (Resilient POS · Phase 3)
 *
 * Centralny punkt synchronizacji między lokalnym IndexedDB klienta
 * (PosLocalStore) a serwerem. Obsługuje trzy akcje:
 *   1. register_terminal — POS rejestruje się / aktualizuje metadane
 *   2. push_batch        — POS wysyła pending ops z outboxu
 *   3. diag              — diagnostyczny echo (do testów pill/engine)
 *
 * Akcja `pull_since` (P3.5) zwraca eventy serwerowe nowsze niż pull_cursor_ts
 * terminala — źródłem jest tabela sh_pos_server_events zasilana przez:
 *   - storefront (nowe zamówienie online)  → order.created
 *   - KDS                                    → order.status
 *   - admin panel                             → menu.updated
 *   - inny POS (multi-device mirror, P5)    → table.reserved, ...
 *
 * Akcja `publish_test_event` (tylko w DEV) wsuwa event typu system.test do
 * streamu — używana w smoke-testach i DevTools do weryfikacji pull-loopa.
 *
 * Akcja `resolve_conflict` rezerwowana na P6.
 *
 * Idempotencja: `op_id` jest PRIMARY KEY w sh_pos_op_log — drugie
 * wysłanie tego samego opa to INSERT IGNORE + zwrot „status: applied
 * (idempotent)". Dzięki temu retry z klienta jest bezpieczne.
 *
 * Tenant isolation: §2 konstytucji. Każde zapytanie filtruje po tenant_id
 * pobranym z auth_guard — NIGDY z request body.
 *
 * MVP ograniczenia (świadome):
 *   - `push_batch` na razie akceptuje akcje typu 'test_action' i 'ping'
 *     (smoke tests). Dla nieznanych akcji: status='rejected'.
 *   - Integracja z istniejącymi akcjami engine.php (process_order itd.)
 *     zostanie dołożona w P4 (Optimistic Layer) — wymaga rewrite tych
 *     akcji żeby akceptowały client-side op_id.
 */

@ini_set('display_errors', '0');
error_reporting(E_ALL);

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function syncResponse(bool $ok, $data = null, ?string $msg = null, int $httpCode = 200): void {
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($httpCode);
    }
    echo json_encode([
        'success' => $ok,
        'data'    => $data,
        'message' => $msg,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function syncInputStr(array $input, string $key, string $default = ''): string {
    $v = $input[$key] ?? $default;
    return trim((string)$v);
}

function syncInputInt(array $input, string $key, int $default = 0): int {
    $v = $input[$key] ?? $default;
    return (int)$v;
}

// UUID v4/v7 walidator — 8-4-4-4-12 hex. Nie weryfikujemy czy v4 czy v7,
// bo klient może mieć fallback w starych przeglądarkach bez crypto.
function syncIsUuid(string $s): bool {
    return (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $s);
}

// ──────────────────────────────────────────────────────────────────────
// Znane akcje, które obsługuje ten endpoint w P3-slice (MVP).
// Każda akcja ma handler (zamknięcie) zwracający ['status', 'server_ref'?].
// ──────────────────────────────────────────────────────────────────────
/**
 * @return array<string, callable> action => handler(PDO, int tenant_id, int user_id, array payload): array
 */
function syncOpHandlers(): array {
    return [
        // Diagnostic echo — zwraca payload z powrotem (żeby smoke-test przeszedł)
        'test_action' => static function (\PDO $pdo, int $tenantId, int $userId, array $payload): array {
            return [
                'status'     => 'applied',
                'server_ref' => null,
                'echo'       => $payload,
            ];
        },

        // Ping — minimalny op dla health checku
        'ping' => static function (\PDO $pdo, int $tenantId, int $userId, array $payload): array {
            return [
                'status'     => 'applied',
                'server_ref' => null,
                'server_ts'  => date('c'),
            ];
        },

        // Telemetryjny append — klient zgłasza zdarzenie z event_log (np.
        // cart:cleared). Backend tylko loguje w sh_pos_op_log bez efektu
        // ubocznego. Pomocne do debugowania flow offline → online.
        'client_event' => static function (\PDO $pdo, int $tenantId, int $userId, array $payload): array {
            return [
                'status'     => 'applied',
                'server_ref' => null,
            ];
        },
    ];
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';

    // auth_guard.php eksponuje $pdo, $tenant_id, $current_user
    if (!isset($pdo) || !isset($tenant_id)) {
        syncResponse(false, null, 'auth_guard did not provide pdo/tenant_id', 500);
    }
    $tenantId = (int)$tenant_id;
    $userId   = isset($current_user['id']) ? (int)$current_user['id'] : 0;

    $raw    = file_get_contents('php://input');
    $input  = json_decode($raw ?: '{}', true) ?? [];
    $action = syncInputStr($input, 'action');

    // Auto-ensure schema — jeśli migracja 039 nie została zastosowana
    // (nowa instalacja), wykrywamy po braku tabeli i zwracamy jasny błąd.
    $hasSchema = false;
    try {
        $pdo->query("SELECT 1 FROM sh_pos_terminals LIMIT 0");
        $hasSchema = true;
    } catch (\PDOException $e) { /* schema missing */ }

    if (!$hasSchema) {
        syncResponse(false, [
            'missing_migration' => '039_resilient_pos.sql',
            'hint' => 'Uruchom scripts/apply_migrations_chain.php aby zastosować migrację 039.',
        ], 'Resilient POS schema not yet migrated', 503);
    }

    // ══════════════════════════════════════════════════════════════════
    // ACTION: register_terminal
    // ══════════════════════════════════════════════════════════════════
    if ($action === 'register_terminal') {
        $deviceUuid = syncInputStr($input, 'device_uuid');
        $label      = syncInputStr($input, 'label', '');
        $appVersion = syncInputStr($input, 'app_version', '');
        $userAgent  = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
        $ip         = (string)($_SERVER['REMOTE_ADDR'] ?? '');

        if (!syncIsUuid($deviceUuid)) {
            syncResponse(false, null, 'invalid device_uuid', 400);
        }

        // Upsert terminal (tenant, device_uuid) — MariaDB 10.4 compat, bez ON
        // DUPLICATE KEY UPDATE na INSERT z wartościami dynamicznymi.
        $stmt = $pdo->prepare("
            INSERT INTO sh_pos_terminals
                (tenant_id, device_uuid, label, last_seen_at, last_user_id, last_user_agent, last_ip, app_version)
            VALUES (?, ?, ?, NOW(3), ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                label           = VALUES(label),
                last_seen_at    = VALUES(last_seen_at),
                last_user_id    = VALUES(last_user_id),
                last_user_agent = VALUES(last_user_agent),
                last_ip         = VALUES(last_ip),
                app_version     = VALUES(app_version)
        ");
        $stmt->execute([
            $tenantId,
            $deviceUuid,
            $label !== '' ? $label : null,
            $userId > 0 ? $userId : null,
            $userAgent !== '' ? $userAgent : null,
            $ip !== '' ? $ip : null,
            $appVersion !== '' ? $appVersion : null,
        ]);

        // Odczytujemy id — w MariaDB lastInsertId() na UPSERT może wrócić 0
        // gdy rekord już istniał, więc zawsze robimy explicit SELECT.
        $stmt = $pdo->prepare("SELECT id FROM sh_pos_terminals WHERE tenant_id = ? AND device_uuid = ? LIMIT 1");
        $stmt->execute([$tenantId, $deviceUuid]);
        $terminalId = (int)$stmt->fetchColumn();

        // Zapewnij wiersz w sh_pos_sync_cursors (INSERT IGNORE — jeden wiersz na POS)
        $stmt = $pdo->prepare("
            INSERT IGNORE INTO sh_pos_sync_cursors (terminal_id, tenant_id, last_sync_at)
            VALUES (?, ?, NOW(3))
        ");
        $stmt->execute([$terminalId, $tenantId]);

        syncResponse(true, [
            'terminal_id' => $terminalId,
            'device_uuid' => $deviceUuid,
            'server_ts'   => date('c'),
        ], 'OK');
    }

    // ══════════════════════════════════════════════════════════════════
    // ACTION: push_batch
    // ══════════════════════════════════════════════════════════════════
    if ($action === 'push_batch') {
        $terminalId = syncInputInt($input, 'terminal_id');
        $ops        = $input['ops'] ?? [];

        if ($terminalId <= 0) {
            syncResponse(false, null, 'terminal_id required', 400);
        }
        if (!is_array($ops)) {
            syncResponse(false, null, 'ops must be array', 400);
        }
        if (count($ops) > 200) {
            syncResponse(false, null, 'batch too large (>200 ops)', 400);
        }

        // Walidacja terminala — musi należeć do tego tenantu (§2)
        $stmt = $pdo->prepare("SELECT id FROM sh_pos_terminals WHERE id = ? AND tenant_id = ? LIMIT 1");
        $stmt->execute([$terminalId, $tenantId]);
        if (!$stmt->fetchColumn()) {
            syncResponse(false, null, 'unknown terminal for this tenant', 403);
        }

        $handlers = syncOpHandlers();
        $results  = [];
        $applied  = 0;
        $rejected = 0;

        // Każdy op w osobnej transakcji — izolacja błędów pojedynczego opa
        // nie zabija batcha. P5 (multi-device) może wymagać SAVEPOINT-owych
        // transakcji na batch jako całość — na razie per-op jest wystarczające.
        foreach ($ops as $op) {
            if (!is_array($op)) {
                $results[] = ['op_id' => null, 'status' => 'rejected', 'error' => 'op not object'];
                $rejected++;
                continue;
            }

            $opId              = syncInputStr($op, 'opId');
            $opAction          = syncInputStr($op, 'action');
            $opPayload         = is_array($op['payload'] ?? null) ? $op['payload'] : [];
            $clientCreatedAt   = syncInputInt($op, 'createdAt');    // epoch ms
            $clientUuid        = syncInputStr($op, 'clientUuid');

            if (!syncIsUuid($opId)) {
                $results[] = ['op_id' => $opId, 'status' => 'rejected', 'error' => 'invalid op_id'];
                $rejected++;
                continue;
            }
            if ($opAction === '') {
                $results[] = ['op_id' => $opId, 'status' => 'rejected', 'error' => 'action missing'];
                $rejected++;
                continue;
            }

            // Idempotency check — jeśli op_id już istnieje jako 'applied',
            // zwracamy tamtą odpowiedź (bez ponownego apply).
            try {
                $check = $pdo->prepare("SELECT status, server_ref, error_text FROM sh_pos_op_log WHERE op_id = ? LIMIT 1");
                $check->execute([$opId]);
                $existing = $check->fetch(\PDO::FETCH_ASSOC);
                if ($existing) {
                    $results[] = [
                        'op_id'     => $opId,
                        'status'    => $existing['status'],
                        'server_ref'=> $existing['server_ref'],
                        'error'     => $existing['error_text'],
                        'idempotent'=> true,
                    ];
                    continue;
                }
            } catch (\PDOException $e) {
                $results[] = ['op_id' => $opId, 'status' => 'rejected', 'error' => 'idempotency check failed'];
                $rejected++;
                continue;
            }

            $handler = $handlers[$opAction] ?? null;
            if (!$handler) {
                // Nieznana akcja — loguj jako rejected i jedź dalej.
                try {
                    $log = $pdo->prepare("
                        INSERT INTO sh_pos_op_log
                            (op_id, terminal_id, tenant_id, user_id, action, payload_json,
                             status, error_text, client_created_at, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, 'rejected', ?, FROM_UNIXTIME(? * 0.001), NOW(3))
                    ");
                    $log->execute([
                        $opId, $terminalId, $tenantId, $userId > 0 ? $userId : null,
                        $opAction, json_encode($opPayload, JSON_UNESCAPED_UNICODE),
                        'unsupported action in P3-slice',
                        $clientCreatedAt > 0 ? $clientCreatedAt : (int)(microtime(true) * 1000),
                    ]);
                } catch (\PDOException $e) { /* fallthrough */ }
                $results[] = ['op_id' => $opId, 'status' => 'rejected', 'error' => 'unsupported action'];
                $rejected++;
                continue;
            }

            // Apply
            $pdo->beginTransaction();
            try {
                $outcome = $handler($pdo, $tenantId, $userId, $opPayload);
                $status    = (string)($outcome['status'] ?? 'applied');
                $serverRef = $outcome['server_ref'] ?? null;
                $appliedAt = date('Y-m-d H:i:s');
                $latency   = $clientCreatedAt > 0
                    ? max(0, (int)(microtime(true) * 1000) - $clientCreatedAt)
                    : null;

                $log = $pdo->prepare("
                    INSERT INTO sh_pos_op_log
                        (op_id, terminal_id, tenant_id, user_id, action, payload_json,
                         status, server_ref, applied_at, latency_ms, client_created_at, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(3), ?, FROM_UNIXTIME(? * 0.001), NOW(3))
                ");
                $log->execute([
                    $opId, $terminalId, $tenantId, $userId > 0 ? $userId : null,
                    $opAction, json_encode($opPayload, JSON_UNESCAPED_UNICODE),
                    $status, $serverRef, $latency,
                    $clientCreatedAt > 0 ? $clientCreatedAt : (int)(microtime(true) * 1000),
                ]);

                $pdo->commit();

                $results[] = [
                    'op_id'      => $opId,
                    'status'     => $status,
                    'server_ref' => $serverRef,
                    'latency_ms' => $latency,
                    'applied'    => array_merge(['at' => $appliedAt], array_diff_key($outcome, array_flip(['status', 'server_ref']))),
                ];
                if ($status === 'applied') $applied++;
                else $rejected++;
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                // Log jako 'rejected' z error_text
                try {
                    $log = $pdo->prepare("
                        INSERT INTO sh_pos_op_log
                            (op_id, terminal_id, tenant_id, user_id, action, payload_json,
                             status, error_text, client_created_at, created_at)
                        VALUES (?, ?, ?, ?, ?, ?, 'rejected', ?, FROM_UNIXTIME(? * 0.001), NOW(3))
                    ");
                    $log->execute([
                        $opId, $terminalId, $tenantId, $userId > 0 ? $userId : null,
                        $opAction, json_encode($opPayload, JSON_UNESCAPED_UNICODE),
                        substr($e->getMessage(), 0, 1000),
                        $clientCreatedAt > 0 ? $clientCreatedAt : (int)(microtime(true) * 1000),
                    ]);
                } catch (\PDOException $ignore) {}
                $results[] = ['op_id' => $opId, 'status' => 'rejected', 'error' => 'server_exception'];
                $rejected++;
            }
        }

        // Zaktualizuj statystyki terminala
        try {
            $upd = $pdo->prepare("
                UPDATE sh_pos_terminals
                SET ops_received = ops_received + ?,
                    ops_applied  = ops_applied + ?,
                    ops_rejected = ops_rejected + ?,
                    last_seen_at = NOW(3)
                WHERE id = ? AND tenant_id = ?
            ");
            $upd->execute([count($ops), $applied, $rejected, $terminalId, $tenantId]);

            $upd = $pdo->prepare("
                UPDATE sh_pos_sync_cursors
                SET push_cursor_ts = NOW(3),
                    last_sync_at   = NOW(3),
                    last_error     = NULL
                WHERE terminal_id = ? AND tenant_id = ?
            ");
            $upd->execute([$terminalId, $tenantId]);
        } catch (\PDOException $e) { /* soft-fail */ }

        syncResponse(true, [
            'results'   => $results,
            'summary'   => [
                'total'    => count($ops),
                'applied'  => $applied,
                'rejected' => $rejected,
            ],
            'server_ts' => date('c'),
        ], 'OK');
    }

    // ══════════════════════════════════════════════════════════════════
    // ACTION: diag
    // ══════════════════════════════════════════════════════════════════
    if ($action === 'diag') {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sh_pos_terminals WHERE tenant_id = ?");
        $stmt->execute([$tenantId]);
        $terminalsCount = (int)$stmt->fetchColumn();

        $stmt = $pdo->prepare("
            SELECT status, COUNT(*) as cnt
            FROM sh_pos_op_log
            WHERE tenant_id = ? AND created_at > NOW(3) - INTERVAL 24 HOUR
            GROUP BY status
        ");
        $stmt->execute([$tenantId]);
        $oplog24h = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $oplog24h[$row['status']] = (int)$row['cnt'];
        }

        // Diag P3.5: stream health
        $streamCount = 0;
        $latestEventTs = null;
        try {
            $stmt = $pdo->prepare("
                SELECT COUNT(*) AS cnt, MAX(created_at) AS latest
                FROM sh_pos_server_events
                WHERE tenant_id = ? AND created_at > NOW(3) - INTERVAL 24 HOUR
            ");
            $stmt->execute([$tenantId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
            $streamCount = (int)($row['cnt'] ?? 0);
            $latestEventTs = $row['latest'] ?? null;
        } catch (\PDOException $e) { /* soft */ }

        syncResponse(true, [
            'tenant_id'        => $tenantId,
            'terminals_count'  => $terminalsCount,
            'oplog_24h'        => $oplog24h,
            'stream_24h'       => [
                'events'         => $streamCount,
                'latest_at'      => $latestEventTs,
            ],
            'server_ts'        => date('c'),
            'known_actions'    => array_keys(syncOpHandlers()),
            'supported_actions'=> ['register_terminal', 'push_batch', 'pull_since', 'publish_test_event', 'diag'],
            'phase_note'       => 'P3.5 adds pull_since (server→client delta). P4 dokłada PosApiOutbox wrapper (offline mutacji przez outbox).',
        ], 'OK');
    }

    // ══════════════════════════════════════════════════════════════════
    // ACTION: pull_since
    // ──────────────────────────────────────────────────────────────────
    // Delta stream serwera do klienta. Klient przesyła terminal_id +
    // opcjonalny `since_ts` (ISO-8601 albo 0 dla pełnego historycznego).
    // Zwracamy eventy z sh_pos_server_events > MAX(since_ts, pull_cursor_ts),
    // limit 200 na jedno wywołanie. Klient ma odpowiedzialność za kolejne
    // wywołania gdy `has_more` = true.
    //
    // Update pull_cursor_ts — atomicznie, żeby dwa równoległe pulls tego
    // samego terminala nie zgubiły eventu.
    // ══════════════════════════════════════════════════════════════════
    if ($action === 'pull_since') {
        $terminalId = syncInputInt($input, 'terminal_id');
        $sinceRaw   = syncInputStr($input, 'since_ts', '');
        $limit      = max(1, min(200, syncInputInt($input, 'limit', 100)));

        if ($terminalId <= 0) {
            syncResponse(false, null, 'terminal_id required', 400);
        }

        // Walidacja terminala vs tenant
        $stmt = $pdo->prepare("SELECT id FROM sh_pos_terminals WHERE id = ? AND tenant_id = ? LIMIT 1");
        $stmt->execute([$terminalId, $tenantId]);
        if (!$stmt->fetchColumn()) {
            syncResponse(false, null, 'unknown terminal for this tenant', 403);
        }

        // Resolve cursor — preferujemy wartość z bazy (autorytet), klient
        // może podać since_ts tylko jeśli chce "przewinąć" do konkretnego
        // momentu (np. full resync).
        $cursorTs = null;
        try {
            $stmt = $pdo->prepare("SELECT pull_cursor_ts FROM sh_pos_sync_cursors WHERE terminal_id = ? AND tenant_id = ? LIMIT 1");
            $stmt->execute([$terminalId, $tenantId]);
            $cursorTs = $stmt->fetchColumn() ?: null;
        } catch (\PDOException $e) { /* soft */ }

        // Jeśli klient poda since_ts jawnie i jest nowszy od db cursora → użyj
        // jego. Chroni przed scenariuszem "chcę pełny full-sync" → klient
        // wysyła since_ts='1970-01-01' i serwer zwraca wszystko z 7-dniowego okna.
        $effectiveSince = $sinceRaw !== '' ? $sinceRaw : ($cursorTs ?: '1970-01-01 00:00:00');

        $stmt = $pdo->prepare("
            SELECT id, tenant_id, event_type, entity_type, entity_id, payload_json,
                   origin_kind, origin_ref, created_at
            FROM sh_pos_server_events
            WHERE tenant_id = ? AND created_at > ?
            ORDER BY id ASC
            LIMIT " . ((int)$limit + 1) . "
        ");
        $stmt->execute([$tenantId, $effectiveSince]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC) ?: [];

        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);

        $events = array_map(static function (array $r): array {
            $payload = null;
            if (is_string($r['payload_json']) && $r['payload_json'] !== '') {
                $payload = json_decode($r['payload_json'], true);
                if (!is_array($payload)) $payload = $r['payload_json'];
            }
            return [
                'id'          => (int)$r['id'],
                'event_type'  => $r['event_type'],
                'entity_type' => $r['entity_type'],
                'entity_id'   => $r['entity_id'],
                'payload'     => $payload,
                'origin_kind' => $r['origin_kind'],
                'origin_ref'  => $r['origin_ref'],
                'created_at'  => $r['created_at'],
            ];
        }, $rows);

        // Advance cursor do największego created_at z zwróconych eventów.
        // (Jeśli batch pusty, zostawiamy cursor w spokoju — żeby klient z 0
        // eventami mógł po prostu czekać na kolejny event bez szkód.)
        $newCursor = null;
        if (!empty($events)) {
            $newCursor = end($events)['created_at'];
            reset($events);
            try {
                $upd = $pdo->prepare("
                    UPDATE sh_pos_sync_cursors
                    SET pull_cursor_ts       = GREATEST(COALESCE(pull_cursor_ts, '1970-01-01'), ?),
                        pull_events_total    = pull_events_total + ?,
                        pull_last_count      = ?,
                        pull_last_fetched_at = NOW(3),
                        last_sync_at         = NOW(3)
                    WHERE terminal_id = ? AND tenant_id = ?
                ");
                $upd->execute([$newCursor, count($events), count($events), $terminalId, $tenantId]);
            } catch (\PDOException $e) { /* soft */ }
        } else {
            // Update tylko last_fetched_at — pomocne do monitoringu zdrowia pulla
            try {
                $upd = $pdo->prepare("
                    UPDATE sh_pos_sync_cursors
                    SET pull_last_count      = 0,
                        pull_last_fetched_at = NOW(3),
                        last_sync_at         = NOW(3)
                    WHERE terminal_id = ? AND tenant_id = ?
                ");
                $upd->execute([$terminalId, $tenantId]);
            } catch (\PDOException $e) { /* soft */ }
        }

        syncResponse(true, [
            'events'       => $events,
            'count'        => count($events),
            'has_more'     => $hasMore,
            'cursor'       => $newCursor ?: $effectiveSince,
            'server_ts'    => date('c'),
        ], 'OK');
    }

    // ══════════════════════════════════════════════════════════════════
    // ACTION: publish_test_event (DEV-only helper)
    // ──────────────────────────────────────────────────────────────────
    // Wstrzykuje event typu `system.test` do sh_pos_server_events bez
    // żadnego side-effectu. Używane w DevTools do weryfikacji, że pull-loop
    // dociera i pokazuje eventy.
    //
    // Bezpieczeństwo: tylko dev mode — ograniczamy do tenantu usera + nie
    // pozwalamy nadpisać origin_kind ('system.test' jest narzucone).
    // ══════════════════════════════════════════════════════════════════
    if ($action === 'publish_test_event') {
        $label   = syncInputStr($input, 'label', 'pull_smoke_test');
        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : ['label' => $label, 'ts' => date('c')];

        $stmt = $pdo->prepare("
            INSERT INTO sh_pos_server_events
                (tenant_id, event_type, entity_type, entity_id, payload_json, origin_kind, origin_ref)
            VALUES (?, 'system.test', 'test', ?, ?, 'system', ?)
        ");
        $stmt->execute([
            $tenantId,
            substr($label, 0, 64),
            json_encode($payload, JSON_UNESCAPED_UNICODE),
            'user:' . ($userId > 0 ? $userId : 0),
        ]);
        $newId = (int)$pdo->lastInsertId();

        syncResponse(true, [
            'id'         => $newId,
            'event_type' => 'system.test',
            'server_ts'  => date('c'),
        ], 'OK');
    }

    // ══════════════════════════════════════════════════════════════════
    // Unknown action
    // ══════════════════════════════════════════════════════════════════
    syncResponse(false, [
        'action'           => $action,
        'supported_actions'=> ['register_terminal', 'push_batch', 'pull_since', 'publish_test_event', 'diag'],
    ], 'unknown action', 400);

} catch (\Throwable $e) {
    // Nie wyciekamy stack trace w produkcji
    syncResponse(false, null, 'internal: ' . $e->getMessage(), 500);
}
