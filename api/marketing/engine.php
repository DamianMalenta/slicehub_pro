<?php
declare(strict_types=1);

/**
 * SliceHub Marketing Engine — kampanie SMS/email do bazy klientów.
 *
 * Akcje:
 *   audience_preview    — ilu klientów spełnia filtry + sample 10
 *   campaign_create     — tworzy kampanię + rozlewa eventy marketing.campaign do outboxa
 *   campaigns_list      — lista kampanii z live progress (sent/failed)
 *   campaign_status     — szczegóły kampanii (audience, delivery stats)
 *   campaign_cancel     — anuluj scheduled kampanię
 *
 * Rate limiting: dla personal_phone max 30/h (limit z tabeli kanału).
 * Worker_notifications.php procesuje je w kolejności z zachowaniem rate limitu.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

require_once __DIR__ . '/../../core/db_config.php';
require_once __DIR__ . '/../../core/auth_guard.php';
require_once __DIR__ . '/../../core/OrderEventPublisher.php';

$raw   = file_get_contents('php://input');
$input = json_decode($raw ?: '{}', true) ?? [];
$action = trim((string)($input['action'] ?? ''));

function mkgResponse(bool $ok, $data = null, ?string $msg = null, int $code = 200): void
{
    http_response_code($code);
    echo json_encode(['success' => $ok, 'data' => $data, 'message' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
}

function buildAudienceQuery(\PDO $pdo, int $tenantId, array $filters, bool $countOnly = false): array
{
    $where   = ['c.tenant_id = :tid'];
    $params  = [':tid' => $tenantId];

    if (!empty($filters['requires_marketing_consent'])) {
        $where[] = 'c.marketing_consent = 1';
    }
    if (!empty($filters['requires_sms_consent'])) {
        $where[] = 'c.sms_consent = 1';
    }
    if (isset($filters['min_orders']) && $filters['min_orders'] > 0) {
        $where[] = 'c.order_count >= :minord';
        $params[':minord'] = (int)$filters['min_orders'];
    }
    if (isset($filters['days_since_last']) && $filters['days_since_last'] > 0) {
        $where[] = 'c.last_order_at >= DATE_SUB(NOW(), INTERVAL :days DAY)';
        $params[':days'] = (int)$filters['days_since_last'];
    }
    if (!empty($filters['opted_in_sms'])) {
        $where[] = 'c.sms_consent = 1 AND (c.sms_optout_at IS NULL OR c.sms_optout_at < c.updated_at)';
    }

    $whereStr = implode(' AND ', $where);
    $select = $countOnly
        ? 'SELECT COUNT(*) FROM sh_customer_contacts c'
        : 'SELECT c.id, c.phone, c.name, c.email, c.order_count, c.last_order_at FROM sh_customer_contacts c';

    return ['sql' => "{$select} WHERE {$whereStr}", 'params' => $params];
}

// ── audience_preview ──────────────────────────────────────────────────────
if ($action === 'audience_preview') {
    $filters = is_array($input['filters'] ?? null) ? $input['filters'] : [];

    try {
        // Count
        $q = buildAudienceQuery($pdo, $tenant_id, $filters, true);
        $cStmt = $pdo->prepare($q['sql']);
        $cStmt->execute($q['params']);
        $total = (int)$cStmt->fetchColumn();

        // Sample
        $q2 = buildAudienceQuery($pdo, $tenant_id, $filters, false);
        $sStmt = $pdo->prepare($q2['sql'] . ' ORDER BY c.last_order_at DESC LIMIT 10');
        $sStmt->execute($q2['params']);
        $sample = $sStmt->fetchAll(\PDO::FETCH_ASSOC);

        mkgResponse(true, ['total' => $total, 'sample' => $sample]);
    } catch (\Throwable $e) {
        mkgResponse(false, null, $e->getMessage(), 500);
    }
}

// ── campaign_create ───────────────────────────────────────────────────────
if ($action === 'campaign_create') {
    $name        = trim((string)($input['name']        ?? ''));
    $channelId   = (int)($input['channel_id']           ?? 0);
    $templateId  = (int)($input['template_id']          ?? 0);
    $filters     = is_array($input['filters'] ?? null)   ? $input['filters']   : ['requires_marketing_consent' => true];
    $scheduledAt = trim((string)($input['scheduled_at'] ?? ''));

    if ($name === '' || $channelId <= 0 || $templateId <= 0) {
        mkgResponse(false, null, 'name, channel_id, template_id są wymagane.', 400);
    }

    // Walidacja kanału i szablonu
    $chStmt = $pdo->prepare("SELECT id, channel_type, rate_limit_per_hour FROM sh_notification_channels WHERE id=:id AND tenant_id=:tid AND is_active=1");
    $chStmt->execute([':id' => $channelId, ':tid' => $tenant_id]);
    $channel = $chStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$channel) mkgResponse(false, null, 'Kanał nie istnieje lub jest wyłączony.', 404);

    $tplStmt = $pdo->prepare("SELECT body FROM sh_notification_templates WHERE id=:id AND tenant_id=:tid AND is_active=1 LIMIT 1");
    $tplStmt->execute([':id' => $templateId, ':tid' => $tenant_id]);
    $template = $tplStmt->fetch(\PDO::FETCH_ASSOC);
    if (!$template) mkgResponse(false, null, 'Szablon nie istnieje.', 404);

    try {
        // Pobierz audience
        $q = buildAudienceQuery($pdo, $tenant_id, $filters, false);
        $aStmt = $pdo->prepare($q['sql']);
        $aStmt->execute($q['params']);
        $contacts = $aStmt->fetchAll(\PDO::FETCH_ASSOC);
        $total = count($contacts);

        if ($total === 0) {
            mkgResponse(false, null, 'Brak kontaktów spełniających kryteria.');
        }

        $pdo->beginTransaction();

        // Utwórz kampanię
        $campaignId = null;
        $pdo->prepare(
            "INSERT INTO sh_marketing_campaigns
                (tenant_id, name, channel_id, template_id, audience_filter_json,
                 status, scheduled_at, total_audience, created_by)
             VALUES (:tid, :name, :cid, :tplid, :filters, :status, :sched, :total, :uid)"
        )->execute([
            ':tid'     => $tenant_id,
            ':name'    => $name,
            ':cid'     => $channelId,
            ':tplid'   => $templateId,
            ':filters' => json_encode($filters),
            ':status'  => $scheduledAt !== '' ? 'scheduled' : 'running',
            ':sched'   => $scheduledAt !== '' ? $scheduledAt : null,
            ':total'   => $total,
            ':uid'     => $user_id,
        ]);
        $campaignId = (string)$pdo->lastInsertId();

        // Rate limit per hour dla personal phone: max 30/h
        $rateLimit = (int)($input['rate_limit_per_hour'] ?? $channel['rate_limit_per_hour'] ?? 30);
        if ($rateLimit <= 0) $rateLimit = 30;

        // Rozlej eventy marketing.campaign do outboxa per kontakt
        // Każdy kontakt dostaje osobny event z inną idempotency_key
        // NotificationDispatcher zadba o rate limiting przez sprawdzanie sh_notification_deliveries
        $countPublished = 0;
        foreach ($contacts as $contact) {
            // Delay scheduling — dla personal phone rozkładamy w czasie
            // Wstępny scheduled_at: co 2s per wiadomość (bezpieczny dla Androida)
            $delaySeconds = $channel['channel_type'] === 'personal_phone'
                ? (int)(ceil($countPublished / max(1, $rateLimit)) * 3600)
                : 0;

            $eventPayload = [
                'customer_phone'    => $contact['phone'],
                'customer_name'     => $contact['name'],
                'customer_email'    => $contact['email'],
                'sms_consent'       => true,
                'marketing_consent' => true,
                'campaign_id'       => $campaignId,
                'contact_id'        => $contact['id'],
                'channel_id'        => $channelId,
                'template_id'       => $templateId,
                '_context' => ['campaign_name' => $name],
                '_meta'    => ['event_type' => 'marketing.campaign', 'published_at' => date('c')],
            ];

            $idk = "mkg:{$campaignId}:{$contact['id']}";
            $pdo->prepare(
                "INSERT IGNORE INTO sh_event_outbox
                    (tenant_id, event_type, aggregate_type, aggregate_id,
                     idempotency_key, payload, source, actor_type, actor_id,
                     status, attempts, next_attempt_at, created_at)
                 VALUES (:tid, 'marketing.campaign', 'campaign', :cid,
                         :idk, :pl, 'marketing', 'staff', :uid,
                         'pending', 0, DATE_ADD(NOW(), INTERVAL :delay SECOND), NOW())"
            )->execute([
                ':tid'   => $tenant_id,
                ':cid'   => $campaignId,
                ':idk'   => $idk,
                ':pl'    => json_encode($eventPayload, JSON_UNESCAPED_UNICODE),
                ':uid'   => (string)$user_id,
                ':delay' => $delaySeconds,
            ]);
            $countPublished++;
        }

        $pdo->commit();

        mkgResponse(true, [
            'campaign_id'     => $campaignId,
            'audience'        => $total,
            'events_queued'   => $countPublished,
            'status'          => $scheduledAt !== '' ? 'scheduled' : 'running',
        ], "Kampania '{$name}' uruchomiona dla {$total} kontaktów.");

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        mkgResponse(false, null, $e->getMessage(), 500);
    }
}

// ── campaigns_list ────────────────────────────────────────────────────────
if ($action === 'campaigns_list') {
    try {
        $stmt = $pdo->prepare(
            "SELECT c.id, c.name, c.status, c.scheduled_at, c.started_at, c.completed_at,
                    c.total_audience, c.sent_count, c.failed_count, c.created_at,
                    ch.name AS channel_name, ch.channel_type,
                    (SELECT COUNT(*) FROM sh_notification_deliveries d
                     WHERE d.event_type='marketing.campaign'
                       AND d.tenant_id=c.tenant_id
                       AND JSON_CONTAINS(d.recipient, CONCAT('\"', c.id, '\"'), '\$.campaign_id')
                    ) AS delivered_count
             FROM sh_marketing_campaigns c
             LEFT JOIN sh_notification_channels ch ON ch.id = c.channel_id
             WHERE c.tenant_id = :tid
             ORDER BY c.created_at DESC
             LIMIT 50"
        );
        $stmt->execute([':tid' => $tenant_id]);
        mkgResponse(true, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    } catch (\Throwable $e) {
        // Fallback bez subquery jeśli JSON_CONTAINS nie zadziała
        try {
            $stmt = $pdo->prepare(
                "SELECT c.id, c.name, c.status, c.scheduled_at, c.total_audience,
                        c.sent_count, c.failed_count, c.created_at,
                        ch.name AS channel_name, ch.channel_type
                 FROM sh_marketing_campaigns c
                 LEFT JOIN sh_notification_channels ch ON ch.id = c.channel_id
                 WHERE c.tenant_id = :tid
                 ORDER BY c.created_at DESC LIMIT 50"
            );
            $stmt->execute([':tid' => $tenant_id]);
            mkgResponse(true, $stmt->fetchAll(\PDO::FETCH_ASSOC));
        } catch (\Throwable $e2) {
            mkgResponse(false, null, $e2->getMessage(), 500);
        }
    }
}

// ── campaign_status ───────────────────────────────────────────────────────
if ($action === 'campaign_status') {
    $campaignId = (string)($input['campaign_id'] ?? '');
    if ($campaignId === '') mkgResponse(false, null, 'campaign_id required', 400);

    try {
        $stmt = $pdo->prepare(
            "SELECT c.*, ch.name AS channel_name, ch.channel_type
             FROM sh_marketing_campaigns c
             LEFT JOIN sh_notification_channels ch ON ch.id = c.channel_id
             WHERE c.id = :cid AND c.tenant_id = :tid LIMIT 1"
        );
        $stmt->execute([':cid' => $campaignId, ':tid' => $tenant_id]);
        $campaign = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$campaign) mkgResponse(false, null, 'Campaign not found.', 404);

        // Live delivery stats z outboxa
        $stmtQ = $pdo->prepare(
            "SELECT status, COUNT(*) AS cnt
             FROM sh_event_outbox
             WHERE aggregate_id = :cid AND event_type = 'marketing.campaign' AND tenant_id = :tid
             GROUP BY status"
        );
        $stmtQ->execute([':cid' => $campaignId, ':tid' => $tenant_id]);
        $queueStats = [];
        while ($row = $stmtQ->fetch(\PDO::FETCH_ASSOC)) {
            $queueStats[$row['status']] = (int)$row['cnt'];
        }

        mkgResponse(true, array_merge($campaign, ['queue_stats' => $queueStats]));
    } catch (\Throwable $e) {
        mkgResponse(false, null, $e->getMessage(), 500);
    }
}

// ── campaign_cancel ───────────────────────────────────────────────────────
if ($action === 'campaign_cancel') {
    $campaignId = (string)($input['campaign_id'] ?? '');
    if ($campaignId === '') mkgResponse(false, null, 'campaign_id required', 400);

    try {
        $pdo->beginTransaction();

        // Anuluj kampanię
        $pdo->prepare(
            "UPDATE sh_marketing_campaigns SET status='cancelled', completed_at=NOW()
             WHERE id=:cid AND tenant_id=:tid AND status IN ('scheduled','running')"
        )->execute([':cid' => $campaignId, ':tid' => $tenant_id]);

        // Anuluj pending eventy w outboxie
        $pdo->prepare(
            "UPDATE sh_event_outbox SET status='cancelled'
             WHERE aggregate_id=:cid AND event_type='marketing.campaign'
               AND tenant_id=:tid AND status='pending'"
        )->execute([':cid' => $campaignId, ':tid' => $tenant_id]);

        $pdo->commit();
        mkgResponse(true, null, 'Kampania anulowana.');
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        mkgResponse(false, null, $e->getMessage(), 500);
    }
}

mkgResponse(false, null, "Unknown action: '{$action}'", 400);
