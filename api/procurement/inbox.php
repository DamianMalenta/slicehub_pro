<?php

declare(strict_types=1);

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

/**
 * SliceHub — Procurement / KSeF Inbox endpoint
 *
 * /api/procurement/inbox.php
 *
 * AKCJE (action-based router, Konstytucja v5 § Prawo V):
 *   - list           — lista faktur w inbox-ie (filtr: status, supplier_nip, paging)
 *   - show           — szczegóły faktury z liniami + AutoScan match per linia
 *   - upload_xml     — manual upload FA(2) XML (drag&drop UI) — parse → save → match
 *   - reparse        — ponowny AutoScan match (po dodaniu nowych aliasów / mappingów)
 *   - update_line    — manual override resolved_sku per linia (przed accept)
 *   - accept         — utwórz PZ przez PzEngine + auto-learn ALIAS mappings + status=accepted
 *   - reject         — soft-reject (status=rejected, rejected_reason)
 *
 * AUTH (Konstytucja v5 § Prawo IV):
 *   auth_guard.php → tenant_id + user_id z sesji/JWT.
 *
 * RBAC granularny:
 *   - list / show: owner / admin / manager
 *   - upload_xml: owner / admin / manager (każdy może wrzucić fakturę)
 *   - reparse / update_line: owner / manager (operacyjna decyzja)
 *   - accept: owner / manager (PZ tworzy magazyn — to operacyjna decyzja)
 *   - reject: owner / manager
 *
 * AUDIT do sh_settings_audit dla destrukcyjnych akcji (accept, reject).
 *
 * Architektura — F3 MVP (manual upload):
 *   1. User dragguje FA(2) XML w UI → POST upload_xml z `xml` w body.
 *   2. Parser zapisuje raw XML + parsed metadata do sh_ksef_invoices.
 *   3. Linie zapisane do sh_ksef_invoice_lines.
 *   4. AutoScanEngine::matchBulk() match-uje wszystkie linie naraz.
 *   5. Manager przegląda w UI, ewentualnie override-uje sugestie.
 *   6. Klika "Akceptuj" → PzEngine::processReceipt utworzy wh_documents (PZ)
 *      + auto-learn ALIAS mappings → faktura status='accepted'.
 *
 * F4 (przyszłość): worker_ksef_inbox.php zamiast manual upload — to samo
 * `sh_ksef_invoices`, ale wpisywane przez cron poll KSeF API.
 *
 * Sesja F3 · 2026-05-11.
 */

function inboxResponse(bool $ok, $data = null, ?string $msg = null, ?string $code = null): void
{
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $out = ['success' => $ok, 'data' => $data, 'message' => $msg];
    if ($code !== null) $out['code'] = $code;
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

function inboxFail(int $httpCode, string $code, ?string $msg = null): void
{
    http_response_code($httpCode);
    inboxResponse(false, null, $msg ?? $code, $code);
}

function inboxLoadActorRole(PDO $pdo, int $tenantId, int $userId): string
{
    $st = $pdo->prepare(
        'SELECT LOWER(role) FROM sh_users WHERE id = :uid AND tenant_id = :tid AND is_deleted = 0 LIMIT 1'
    );
    $st->execute([':uid' => $userId, ':tid' => $tenantId]);
    $r = $st->fetchColumn();
    return is_string($r) ? $r : '';
}

function inboxRequireRole(string $actorRole, array $allowed): void
{
    if (!in_array($actorRole, $allowed, true)) {
        inboxFail(403, 'FORBIDDEN',
            'Wymagana rola: ' . implode(' / ', $allowed) . '. Aktualna: ' . ($actorRole ?: 'unknown') . '.');
    }
}

function inboxAudit(
    PDO $pdo, int $tenantId, int $userId,
    string $action, ?int $invoiceId, array $after
): void {
    try {
        $st = $pdo->prepare(
            "INSERT INTO sh_settings_audit
                (tenant_id, user_id, actor_ip, action, entity_type, entity_id, before_json, after_json)
             VALUES
                (:tid, :uid, :ip, :act, 'ksef_invoice', :eid, NULL, :after)"
        );
        $st->execute([
            ':tid'   => $tenantId,
            ':uid'   => $userId,
            ':ip'    => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
            ':act'   => $action,
            ':eid'   => $invoiceId,
            ':after' => json_encode($after, JSON_UNESCAPED_UNICODE),
        ]);
    } catch (\Throwable $e) {
        error_log('[procurement/inbox.audit] ' . $e->getMessage());
    }
}

/**
 * Match wszystkie linie faktury przez AutoScan i zapisz wynik per linia.
 */
function inboxRescanLines(PDO $pdo, int $tenantId, int $invoiceId): array
{
    $st = $pdo->prepare(
        "SELECT id, line_no, external_name FROM sh_ksef_invoice_lines
          WHERE ksef_invoice_id = :iid ORDER BY line_no"
    );
    $st->execute([':iid' => $invoiceId]);
    $lines = $st->fetchAll(PDO::FETCH_ASSOC);

    $upd = $pdo->prepare(
        "UPDATE sh_ksef_invoice_lines
            SET resolved_sku = :sku,
                match_type = :mt,
                match_confidence = :conf,
                match_candidates_json = :cand,
                resolved_at = NOW(),
                resolved_by_user_id = NULL
          WHERE id = :id AND ksef_invoice_id = :iid"
    );

    $stats = ['EXACT' => 0, 'ALIAS' => 0, 'NAME' => 0, 'FUZZY' => 0, 'NONE' => 0, 'auto_accept' => 0];
    foreach ($lines as $line) {
        $r = AutoScanEngine::match($pdo, $tenantId, (string) $line['external_name']);
        $upd->execute([
            ':sku'  => $r['sku'],
            ':mt'   => $r['match_type'] ?? 'NONE',
            ':conf' => $r['confidence'] ?? 0,
            ':cand' => json_encode($r['candidates'] ?? [], JSON_UNESCAPED_UNICODE),
            ':id'   => $line['id'],
            ':iid'  => $invoiceId,
        ]);
        $mt = $r['match_type'] ?? 'NONE';
        $stats[$mt] = ($stats[$mt] ?? 0) + 1;
        if (!empty($r['should_auto_accept'])) $stats['auto_accept']++;
    }
    $stats['total'] = count($lines);
    return $stats;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/auth_guard.php';
    require_once __DIR__ . '/../../core/AutoScanEngine.php';
    require_once __DIR__ . '/../../core/Ksef/Parser.php';
    require_once __DIR__ . '/../../core/PzEngine.php';

    /** @var PDO $pdo */
    /** @var int $tenant_id */
    /** @var int $user_id */

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        inboxFail(405, 'METHOD_NOT_ALLOWED', 'Only POST is allowed.');
    }

    $raw = file_get_contents('php://input') ?: '{}';
    $input = json_decode($raw, true);
    if (!is_array($input)) {
        inboxFail(400, 'INVALID_JSON', 'Request body must be a JSON object.');
    }

    $action = trim((string) ($input['action'] ?? ''));
    if ($action === '') inboxFail(400, 'ACTION_REQUIRED', 'Missing "action" field.');

    $actorRole = inboxLoadActorRole($pdo, $tenant_id, $user_id);

    switch ($action) {
        // ---------------------------------------------------------------------
        case 'list': {
            inboxRequireRole($actorRole, ['owner', 'admin', 'manager']);
            $status = trim((string) ($input['status'] ?? ''));
            $limit = max(1, min((int) ($input['limit'] ?? 50), 200));

            $sql = "SELECT id, supplier_nip, supplier_name, invoice_number, issue_date,
                           sale_date, total_gross_minor, status, status_message,
                           linked_wh_document_id, fetched_at, processed_at
                      FROM sh_ksef_invoices
                     WHERE tenant_id = :tid";
            $params = [':tid' => $tenant_id];
            if ($status !== '') {
                $sql .= " AND status = :st";
                $params[':st'] = $status;
            }
            $sql .= " ORDER BY fetched_at DESC LIMIT {$limit}";
            $st = $pdo->prepare($sql);
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC);

            // Stats po statusie (badge counters dla UI)
            $stStats = $pdo->prepare(
                "SELECT status, COUNT(*) AS n FROM sh_ksef_invoices WHERE tenant_id = :tid GROUP BY status"
            );
            $stStats->execute([':tid' => $tenant_id]);
            $stats = [];
            foreach ($stStats->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $stats[$r['status']] = (int) $r['n'];
            }

            inboxResponse(true, ['invoices' => $rows, 'stats' => $stats]);
            break;
        }

        // ---------------------------------------------------------------------
        case 'show': {
            inboxRequireRole($actorRole, ['owner', 'admin', 'manager']);
            $iid = (int) ($input['invoice_id'] ?? 0);
            if ($iid <= 0) inboxFail(400, 'INVALID_INVOICE_ID');

            $st = $pdo->prepare(
                "SELECT * FROM sh_ksef_invoices WHERE id = :id AND tenant_id = :tid LIMIT 1"
            );
            $st->execute([':id' => $iid, ':tid' => $tenant_id]);
            $inv = $st->fetch(PDO::FETCH_ASSOC);
            if (!$inv) inboxFail(404, 'NOT_FOUND', 'Faktura nie istnieje.');

            $stL = $pdo->prepare(
                "SELECT id, line_no, external_name, external_description, gtu_code, pkwiu,
                        unit, qty, unit_net, line_net_minor, vat_rate, resolved_sku,
                        match_type, match_confidence, match_candidates_json,
                        resolved_at, resolved_by_user_id
                   FROM sh_ksef_invoice_lines
                  WHERE ksef_invoice_id = :iid
                  ORDER BY line_no"
            );
            $stL->execute([':iid' => $iid]);
            $lines = $stL->fetchAll(PDO::FETCH_ASSOC);
            // Decode candidates JSON dla każdej linii
            foreach ($lines as &$l) {
                $l['match_candidates'] = $l['match_candidates_json']
                    ? json_decode((string) $l['match_candidates_json'], true)
                    : [];
                unset($l['match_candidates_json']);
            }
            unset($l);

            // Threshold per tenant (dla UI — żeby pokazać "auto-accept" badge per linia)
            $thStmt = $pdo->prepare(
                "SELECT setting_value FROM sh_tenant_settings
                  WHERE tenant_id = :tid AND setting_key = 'autoscan_auto_accept_threshold' LIMIT 1"
            );
            $thStmt->execute([':tid' => $tenant_id]);
            $thVal = $thStmt->fetchColumn();
            $threshold = is_string($thVal) && ctype_digit(trim($thVal))
                ? (int) trim($thVal)
                : AutoScanEngine::DEFAULT_AUTO_ACCEPT_THRESHOLD;

            // NIE wysyłamy raw XML w response (ciężki, zwraca tylko parsed_json)
            unset($inv['xml_blob']);
            $inv['parsed_json'] = $inv['parsed_json']
                ? json_decode((string) $inv['parsed_json'], true)
                : null;

            inboxResponse(true, [
                'invoice'   => $inv,
                'lines'     => $lines,
                'threshold' => $threshold,
            ]);
            break;
        }

        // ---------------------------------------------------------------------
        case 'upload_xml': {
            inboxRequireRole($actorRole, ['owner', 'admin', 'manager']);
            $xml = (string) ($input['xml'] ?? '');
            if (trim($xml) === '') inboxFail(400, 'XML_REQUIRED', 'Pole xml jest wymagane.');

            // Weryfikacja buyer NIP — sh_tenant.nip (z m045 legal profile)
            $tenantNip = '';
            $tStmt = $pdo->prepare("SELECT nip FROM sh_tenant WHERE id = :tid LIMIT 1");
            $tStmt->execute([':tid' => $tenant_id]);
            $tenantNip = (string) ($tStmt->fetchColumn() ?: '');

            $parser = new \SliceHub\Ksef\Parser();
            $parsed = $parser->parse($xml);
            if (!$parsed['success']) {
                inboxFail(400, 'PARSE_FAILED',
                    'Nie udało się sparsować FA(2): ' . implode('; ', $parsed['errors']));
            }

            // Walidacja: buyer NIP w XML musi pasować do sh_tenant.nip (jeśli sh_tenant ma NIP)
            $buyerNip = preg_replace('/\D+/', '', (string) ($parsed['buyer']['nip'] ?? '')) ?? '';
            $myNip = preg_replace('/\D+/', '', $tenantNip) ?? '';
            if ($myNip !== '' && $buyerNip !== '' && $buyerNip !== $myNip) {
                inboxFail(400, 'WRONG_BUYER_NIP',
                    "Faktura wystawiona na inny NIP nabywcy. Oczekiwano: {$myNip}, w XML: {$buyerNip}. Sprawdź czy faktura jest skierowana do Twojej firmy.");
            }

            // INSERT do sh_ksef_invoices
            $pdo->beginTransaction();
            try {
                $stInv = $pdo->prepare(
                    "INSERT INTO sh_ksef_invoices
                        (tenant_id, supplier_nip, supplier_name, supplier_address,
                         buyer_nip, buyer_name, invoice_number,
                         issue_date, sale_date, payment_due_date, currency,
                         total_net_minor, total_vat_minor, total_gross_minor,
                         xml_blob, parsed_json, status)
                     VALUES
                        (:tid, :snip, :sname, :saddr,
                         :bnip, :bname, :inum,
                         :issd, :sald, :payd, :cur,
                         :tnet, :tvat, :tgross,
                         :xml, :pjson, 'draft')"
                );
                $stInv->execute([
                    ':tid'    => $tenant_id,
                    ':snip'   => $parsed['supplier']['nip'] ?: null,
                    ':sname'  => $parsed['supplier']['name'] ?: null,
                    ':saddr'  => $parsed['supplier']['address'] ?: null,
                    ':bnip'   => $parsed['buyer']['nip'] ?: null,
                    ':bname'  => $parsed['buyer']['name'] ?: null,
                    ':inum'   => $parsed['invoice']['number'] ?: ('UPLOAD-' . date('YmdHis')),
                    ':issd'   => $parsed['invoice']['issue_date'],
                    ':sald'   => $parsed['invoice']['sale_date'],
                    ':payd'   => $parsed['invoice']['payment_due_date'],
                    ':cur'    => $parsed['invoice']['currency'] ?: 'PLN',
                    ':tnet'   => $parsed['totals']['total_net_minor'] ?? 0,
                    ':tvat'   => $parsed['totals']['total_vat_minor'] ?? 0,
                    ':tgross' => $parsed['totals']['total_gross_minor'] ?? 0,
                    ':xml'    => $xml,
                    ':pjson'  => json_encode($parsed, JSON_UNESCAPED_UNICODE),
                ]);
                $invoiceId = (int) $pdo->lastInsertId();

                // INSERT lines
                $stLine = $pdo->prepare(
                    "INSERT INTO sh_ksef_invoice_lines
                        (ksef_invoice_id, line_no, external_name, external_description,
                         gtu_code, pkwiu, unit, qty, unit_net, line_net_minor, vat_rate)
                     VALUES
                        (:iid, :lno, :name, :desc, :gtu, :pkwiu, :unit, :qty, :unet, :lnet, :vat)"
                );
                foreach ($parsed['lines'] as $line) {
                    $stLine->execute([
                        ':iid'   => $invoiceId,
                        ':lno'   => $line['line_no'],
                        ':name'  => $line['external_name'],
                        ':desc'  => $line['description'] ?: null,
                        ':gtu'   => $line['gtu_code'] ?: null,
                        ':pkwiu' => $line['pkwiu'] ?: null,
                        ':unit'  => $line['unit'] ?: null,
                        ':qty'   => $line['qty'],
                        ':unet'  => $line['unit_net'],
                        ':lnet'  => $line['line_net_minor'],
                        ':vat'   => $line['vat_rate'],
                    ]);
                }

                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                inboxFail(500, 'INSERT_FAILED', 'Zapis faktury padł: ' . $e->getMessage());
            }

            // Match per linia przez AutoScan
            $matchStats = inboxRescanLines($pdo, $tenant_id, $invoiceId);

            inboxAudit($pdo, $tenant_id, $user_id, 'ksef_upload', $invoiceId, [
                'supplier_nip' => $parsed['supplier']['nip'] ?? '',
                'invoice_no'   => $parsed['invoice']['number'] ?? '',
                'lines_count'  => count($parsed['lines']),
            ]);

            inboxResponse(true, [
                'invoice_id'  => $invoiceId,
                'match_stats' => $matchStats,
                'parsed'      => [
                    'supplier'    => $parsed['supplier'],
                    'invoice'     => $parsed['invoice'],
                    'lines_count' => count($parsed['lines']),
                    'totals'      => $parsed['totals'],
                ],
                'warnings' => $parsed['warnings'] ?? [],
            ], 'Faktura dodana do inbox-a. ' . $matchStats['auto_accept'] . ' / ' . $matchStats['total'] . ' linii gotowe do auto-accept.');
            break;
        }

        // ---------------------------------------------------------------------
        case 'reparse': {
            inboxRequireRole($actorRole, ['owner', 'manager']);
            $iid = (int) ($input['invoice_id'] ?? 0);
            if ($iid <= 0) inboxFail(400, 'INVALID_INVOICE_ID');

            // Tenant-scope check
            $own = $pdo->prepare("SELECT status FROM sh_ksef_invoices WHERE id = :id AND tenant_id = :tid LIMIT 1");
            $own->execute([':id' => $iid, ':tid' => $tenant_id]);
            $status = $own->fetchColumn();
            if ($status === false) inboxFail(404, 'NOT_FOUND');
            if ($status === 'accepted') inboxFail(400, 'ALREADY_ACCEPTED', 'Faktura już zaakceptowana — nie można rescan.');

            $stats = inboxRescanLines($pdo, $tenant_id, $iid);
            inboxResponse(true, ['match_stats' => $stats], 'Rescan zakończony.');
            break;
        }

        // ---------------------------------------------------------------------
        case 'update_line': {
            inboxRequireRole($actorRole, ['owner', 'manager']);
            $iid = (int) ($input['invoice_id'] ?? 0);
            $lid = (int) ($input['line_id'] ?? 0);
            $sku = trim((string) ($input['sku'] ?? ''));
            if ($iid <= 0 || $lid <= 0 || $sku === '') inboxFail(400, 'INVALID_INPUT');

            // Tenant scope + status check
            $own = $pdo->prepare(
                "SELECT i.status FROM sh_ksef_invoices i
                   JOIN sh_ksef_invoice_lines l ON l.ksef_invoice_id = i.id
                  WHERE i.tenant_id = :tid AND i.id = :iid AND l.id = :lid LIMIT 1"
            );
            $own->execute([':tid' => $tenant_id, ':iid' => $iid, ':lid' => $lid]);
            $status = $own->fetchColumn();
            if ($status === false) inboxFail(404, 'NOT_FOUND');
            if ($status === 'accepted') inboxFail(400, 'ALREADY_ACCEPTED');

            // Walidacja SKU w sys_items
            $skuCheck = $pdo->prepare(
                "SELECT 1 FROM sys_items WHERE tenant_id = :tid AND sku = :sku AND is_deleted = 0 AND is_active = 1 LIMIT 1"
            );
            $skuCheck->execute([':tid' => $tenant_id, ':sku' => $sku]);
            if (!$skuCheck->fetchColumn()) {
                inboxFail(400, 'INVALID_SKU', "SKU '{$sku}' nie istnieje w sys_items.");
            }

            $upd = $pdo->prepare(
                "UPDATE sh_ksef_invoice_lines
                    SET resolved_sku = :sku, match_type = 'MANUAL', match_confidence = 100,
                        resolved_at = NOW(), resolved_by_user_id = :uid
                  WHERE id = :lid"
            );
            $upd->execute([':sku' => $sku, ':uid' => $user_id, ':lid' => $lid]);
            inboxResponse(true, ['line_id' => $lid, 'sku' => $sku], 'Linia zaktualizowana.');
            break;
        }

        // ---------------------------------------------------------------------
        case 'accept': {
            inboxRequireRole($actorRole, ['owner', 'manager']);
            $iid = (int) ($input['invoice_id'] ?? 0);
            $warehouseId = trim((string) ($input['warehouse_id'] ?? 'MAIN'));
            if ($iid <= 0) inboxFail(400, 'INVALID_INVOICE_ID');

            // Załaduj fakturę + linie
            $inv = $pdo->prepare("SELECT * FROM sh_ksef_invoices WHERE id = :id AND tenant_id = :tid LIMIT 1");
            $inv->execute([':id' => $iid, ':tid' => $tenant_id]);
            $invoice = $inv->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) inboxFail(404, 'NOT_FOUND');
            if ($invoice['status'] === 'accepted') inboxFail(400, 'ALREADY_ACCEPTED');

            $linesSt = $pdo->prepare(
                "SELECT id, line_no, external_name, qty, unit_net, vat_rate, resolved_sku, match_confidence
                   FROM sh_ksef_invoice_lines WHERE ksef_invoice_id = :iid ORDER BY line_no"
            );
            $linesSt->execute([':iid' => $iid]);
            $lines = $linesSt->fetchAll(PDO::FETCH_ASSOC);

            // Walidacja: wszystkie linie muszą mieć resolved_sku
            $unresolved = [];
            foreach ($lines as $l) {
                if (empty($l['resolved_sku'])) {
                    $unresolved[] = ['line_no' => $l['line_no'], 'external_name' => $l['external_name']];
                }
            }
            if ($unresolved !== []) {
                inboxFail(400, 'UNRESOLVED_LINES',
                    'Nie wszystkie linie mają zmapowany SKU. Użyj update_line lub reparse najpierw. Niedopasowane: '
                    . count($unresolved));
            }

            // Konstruuj payload dla PzEngine
            $pzLines = [];
            foreach ($lines as $l) {
                $pzLines[] = [
                    'external_name' => $l['external_name'],
                    'resolved_sku'  => $l['resolved_sku'],
                    'quantity'      => (float) $l['qty'],
                    'unit_net_cost' => (float) $l['unit_net'],
                    'vat_rate'      => (float) $l['vat_rate'],
                ];
            }

            try {
                $pzResult = PzEngine::processReceipt(
                    $pdo, $tenant_id, $warehouseId,
                    [
                        'supplier_name'    => $invoice['supplier_name'],
                        'supplier_invoice' => $invoice['invoice_number'],
                        'lines'            => $pzLines,
                    ],
                    (string) $user_id
                );
            } catch (\Throwable $e) {
                inboxFail(500, 'PZ_FAILED', 'PZ nie został utworzony: ' . $e->getMessage());
            }

            $pzDocId = (int) ($pzResult['pz_document']['doc_id'] ?? 0);

            // Update faktury: status=accepted, link do PZ
            $pdo->prepare(
                "UPDATE sh_ksef_invoices
                    SET status = 'accepted',
                        linked_wh_document_id = :pzid,
                        processed_at = NOW(),
                        processed_by_user_id = :uid
                  WHERE id = :id AND tenant_id = :tid"
            )->execute([':pzid' => $pzDocId, ':uid' => $user_id, ':id' => $iid, ':tid' => $tenant_id]);

            inboxAudit($pdo, $tenant_id, $user_id, 'ksef_accept', $iid, [
                'pz_doc_id'     => $pzDocId,
                'pz_doc_number' => $pzResult['pz_document']['doc_number'] ?? '',
                'auto_learned'  => $pzResult['pz_document']['auto_learned'] ?? 0,
                'lines_count'   => count($pzLines),
            ]);

            inboxResponse(true, [
                'invoice_id'   => $iid,
                'pz_document'  => $pzResult['pz_document'],
            ], 'Zaakceptowane → PZ ' . ($pzResult['pz_document']['doc_number'] ?? '?'));
            break;
        }

        // ---------------------------------------------------------------------
        case 'reject': {
            inboxRequireRole($actorRole, ['owner', 'manager']);
            $iid = (int) ($input['invoice_id'] ?? 0);
            $reason = trim((string) ($input['reason'] ?? ''));
            if ($iid <= 0) inboxFail(400, 'INVALID_INVOICE_ID');

            $st = $pdo->prepare(
                "UPDATE sh_ksef_invoices
                    SET status = 'rejected',
                        rejected_reason = :reason,
                        processed_at = NOW(),
                        processed_by_user_id = :uid
                  WHERE id = :id AND tenant_id = :tid AND status NOT IN ('accepted')"
            );
            $st->execute([':reason' => $reason ?: null, ':uid' => $user_id, ':id' => $iid, ':tid' => $tenant_id]);
            if ($st->rowCount() === 0) {
                inboxFail(404, 'NOT_FOUND_OR_ACCEPTED');
            }

            inboxAudit($pdo, $tenant_id, $user_id, 'ksef_reject', $iid, ['reason' => $reason]);
            inboxResponse(true, ['invoice_id' => $iid], 'Faktura odrzucona.');
            break;
        }

        // ---------------------------------------------------------------------
        default:
            inboxFail(400, 'UNKNOWN_ACTION', "Nieznana akcja: {$action}");
    }
} catch (\Throwable $e) {
    error_log('[procurement/inbox] FATAL: ' . $e->getMessage());
    inboxFail(500, 'INTERNAL_ERROR', 'Błąd serwera: ' . $e->getMessage());
}
