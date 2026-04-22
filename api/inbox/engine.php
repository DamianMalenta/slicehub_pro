<?php
declare(strict_types=1);

/**
 * Customer Inbox API — skrzynka przychodzących SMS od klientów.
 *
 * Actions (POST JSON body: {"action":"..."}):
 *   inbox_list    — lista wiadomości (z filtrem unread/all, search, limit, offset)
 *   inbox_read    — oznacz wiadomość jako przeczytaną
 *   inbox_bulk_read — oznacz wiele jako przeczytane
 *   inbox_reply   — wyślij ręczną odpowiedź przez personal_phone/sms_gateway
 *   inbox_stats   — liczba nieprzeczytanych, dziś, łącznie
 *
 * Auth: session (role manager/admin).
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/../../core/db_config.php';
require_once __DIR__ . '/../../core/AuthGuard.php';

AuthGuard::requireRole(['manager', 'admin', 'owner']);

$body   = json_decode(file_get_contents('php://input') ?: '{}', true) ?? [];
$action = (string)($body['action'] ?? $_GET['action'] ?? '');
$tid    = (int)($_SESSION['tenant_id'] ?? 1);
$uid    = (int)($_SESSION['user_id']   ?? 0);

function inbox_ok(array $data): void  { echo json_encode(['success' => true]  + $data); exit; }
function inbox_err(string $e): void   { http_response_code(400); echo json_encode(['success' => false, 'error' => $e]); exit; }

switch ($action) {

    // ─── inbox_list ─────────────────────────────────────────────────────────
    case 'inbox_list': {
        $filter  = (string)($body['filter'] ?? 'unread'); // unread | all
        $search  = (string)($body['search'] ?? '');
        $limit   = min((int)($body['limit'] ?? 50), 200);
        $offset  = (int)($body['offset'] ?? 0);

        $where = ['i.tenant_id = :tid'];
        $params = [':tid' => $tid];

        if ($filter === 'unread') {
            $where[] = 'i.read_at IS NULL';
        }
        if ($search !== '') {
            $where[] = '(i.from_phone LIKE :s OR i.body LIKE :s OR c.name LIKE :s)';
            $params[':s'] = '%' . $search . '%';
        }

        $whereStr = 'WHERE ' . implode(' AND ', $where);

        try {
            $countStmt = $pdo->prepare(
                "SELECT COUNT(*) FROM sh_customer_inbox i
                 LEFT JOIN sh_customer_contacts c ON c.id = i.contact_id
                 {$whereStr}"
            );
            $countStmt->execute($params);
            $total = (int)$countStmt->fetchColumn();

            $stmt = $pdo->prepare(
                "SELECT i.id, i.from_phone, i.body, i.intent, i.order_id,
                        i.auto_replied, i.auto_reply_body, i.read_at, i.received_at,
                        c.name AS contact_name, c.email AS contact_email, c.order_count
                 FROM sh_customer_inbox i
                 LEFT JOIN sh_customer_contacts c ON c.id = i.contact_id
                 {$whereStr}
                 ORDER BY i.received_at DESC
                 LIMIT :lim OFFSET :off"
            );
            foreach ($params as $k => $v) $stmt->bindValue($k, $v);
            $stmt->bindValue(':lim', $limit, \PDO::PARAM_INT);
            $stmt->bindValue(':off', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

            inbox_ok(['messages' => $rows, 'total' => $total, 'limit' => $limit, 'offset' => $offset]);
        } catch (\Throwable $e) {
            inbox_err('DB error: ' . $e->getMessage());
        }
    }

    // ─── inbox_read ─────────────────────────────────────────────────────────
    case 'inbox_read': {
        $id = (int)($body['id'] ?? 0);
        if (!$id) inbox_err('id required');
        try {
            $pdo->prepare(
                "UPDATE sh_customer_inbox SET read_at = NOW(), read_by_user_id = :uid
                 WHERE id = :id AND tenant_id = :tid"
            )->execute([':uid' => $uid, ':id' => $id, ':tid' => $tid]);
            inbox_ok(['id' => $id]);
        } catch (\Throwable $e) { inbox_err($e->getMessage()); }
    }

    // ─── inbox_bulk_read ────────────────────────────────────────────────────
    case 'inbox_bulk_read': {
        $ids = array_filter(array_map('intval', (array)($body['ids'] ?? [])));
        if (empty($ids)) inbox_err('ids required');
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        try {
            $stmt = $pdo->prepare(
                "UPDATE sh_customer_inbox SET read_at = NOW(), read_by_user_id = {$uid}
                 WHERE tenant_id = {$tid} AND id IN ({$placeholders})"
            );
            $stmt->execute(array_values($ids));
            inbox_ok(['updated' => $stmt->rowCount()]);
        } catch (\Throwable $e) { inbox_err($e->getMessage()); }
    }

    // ─── inbox_reply ────────────────────────────────────────────────────────
    case 'inbox_reply': {
        $id      = (int)($body['id'] ?? 0);
        $message = trim((string)($body['message'] ?? ''));
        if (!$id || $message === '') inbox_err('id and message required');

        // Pobierz SMS z inbox
        try {
            $msgStmt = $pdo->prepare("SELECT * FROM sh_customer_inbox WHERE id = :id AND tenant_id = :tid");
            $msgStmt->execute([':id' => $id, ':tid' => $tid]);
            $msg = $msgStmt->fetch(\PDO::FETCH_ASSOC);
        } catch (\Throwable $e) { inbox_err($e->getMessage()); }

        if (!$msg) inbox_err('Message not found');

        // Wyślij przez personal_phone (fallback sms_gateway)
        $sent = false;
        $error = null;
        try {
            require_once __DIR__ . '/../../core/Notifications/DeliveryResult.php';
            require_once __DIR__ . '/../../core/Notifications/ChannelInterface.php';
            require_once __DIR__ . '/../../core/Notifications/ChannelRegistry.php';

            ChannelRegistry::setChannelsDir(__DIR__ . '/../../core/Notifications/Channels');

            // Próbuj personal_phone najpierw, potem sms_gateway
            foreach (['personal_phone', 'sms_gateway'] as $chType) {
                $chStmt = $pdo->prepare(
                    "SELECT *, credentials_json FROM sh_notification_channels
                     WHERE tenant_id = :tid AND channel_type = :ct AND is_active = 1
                     ORDER BY priority ASC LIMIT 1"
                );
                $chStmt->execute([':tid' => $tid, ':ct' => $chType]);
                $channelRow = $chStmt->fetch(\PDO::FETCH_ASSOC);
                if (!$channelRow) continue;

                $ch = ChannelRegistry::get($chType);
                if (!$ch) continue;

                $cred = json_decode((string)$channelRow['credentials_json'], true) ?? [];
                $result = $ch->send(
                    (string)$msg['from_phone'],
                    '',
                    $message,
                    array_merge($channelRow, ['credentials' => $cred]),
                    ['order_id' => $msg['order_id'], 'inbox_reply' => true]
                );

                if ($result->success) {
                    $sent = true;
                    break;
                }
                $error = $result->errorMessage;
            }
        } catch (\Throwable $e) {
            $error = $e->getMessage();
        }

        if (!$sent) inbox_err('Send failed: ' . ($error ?? 'no channel available'));

        // Oznacz jako przeczytane i zapisz odpowiedź manualną
        try {
            $pdo->prepare(
                "UPDATE sh_customer_inbox SET read_at = COALESCE(read_at, NOW()), read_by_user_id = :uid
                 WHERE id = :id AND tenant_id = :tid"
            )->execute([':uid' => $uid, ':id' => $id, ':tid' => $tid]);

            // Zapisz w inbox jako nową wychodzącą wiadomość (auto_reply_body nadpisane)
            $pdo->prepare(
                "INSERT INTO sh_customer_inbox
                    (tenant_id, contact_id, from_phone, body, intent, order_id,
                     auto_replied, auto_reply_body, read_at, received_at)
                 SELECT tenant_id, contact_id, :mgr_phone, :msg, 'manager_reply', order_id,
                        1, :msg2, NOW(), NOW()
                 FROM sh_customer_inbox WHERE id = :id AND tenant_id = :tid"
            )->execute([
                ':mgr_phone' => 'manager#' . $uid,
                ':msg'       => $message,
                ':msg2'      => $message,
                ':id'        => $id,
                ':tid'       => $tid,
            ]);
        } catch (\Throwable $e) {}

        inbox_ok(['sent' => true, 'to' => $msg['from_phone']]);
    }

    // ─── inbox_stats ────────────────────────────────────────────────────────
    case 'inbox_stats': {
        try {
            $stmt = $pdo->prepare(
                "SELECT
                    COUNT(*) AS total,
                    SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END) AS unread,
                    SUM(CASE WHEN DATE(received_at) = CURDATE() THEN 1 ELSE 0 END) AS today,
                    SUM(CASE WHEN intent = 'cancel_request' AND read_at IS NULL THEN 1 ELSE 0 END) AS unread_cancels
                 FROM sh_customer_inbox
                 WHERE tenant_id = :tid"
            );
            $stmt->execute([':tid' => $tid]);
            $stats = $stmt->fetch(\PDO::FETCH_ASSOC);
            inbox_ok(['stats' => $stats]);
        } catch (\Throwable $e) { inbox_err($e->getMessage()); }
    }

    default:
        inbox_err('Unknown action: ' . $action);
}
