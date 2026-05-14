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
 *   - upload_xml     — manual upload FA(2) XML (drag&drop UI) — parse → save → match;
 *                      duplikat (tenant + NIP dostawcy + numer FA): HTTP 409 DUPLICATE_INVOICE
 *                      lub `duplicate_resolution: replace` (nie dla accepted + PZ).
 *   - reparse        — ponowny AutoScan match (po dodaniu nowych aliasów / mappingów)
 *   - update_line    — override linii: legacy tylko `sku`; przy migracji 057: `line_type` INVENTORY|EXPENSE,
 *                      INVENTORY + `sku`, EXPENSE + `expense_category_id`
 *   - bulk_update_lines — ta sama logika co update_line, dla wielu line_ids naraz (UI: edycja grupowa / tag OPEX)
 *   - set_cost_category — nagłówek faktury (magazyn/media/…); zwraca błąd USE_LINE_OPEX gdy aktywny model per-linia (057)
 *   - accept         — 057: INVENTORY → payload PzEngine; EXPENSE → tylko UPDATE linii; PZ pomijane gdy brak linii magazynowych
 *   - reject         — soft-reject (status=rejected, rejected_reason)
 *
 * AUTH (Konstytucja v5 § Prawo IV):
 *   auth_guard.php → tenant_id + user_id z sesji/JWT.
 *
 * RBAC granularny:
 *   - list / show: owner / admin / manager
 *   - upload_xml: owner / admin / manager (każdy może wrzucić fakturę)
 *   - reparse / update_line / bulk_update_lines: owner / manager (operacyjna decyzja)
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

/** @return list<string> */
function inboxCostCategoryKeys(): array
{
    return ['magazyn', 'media', 'uslugi', 'inne'];
}

function inboxSanitizeCostCategory(mixed $raw): string
{
    $c = strtolower(trim((string) $raw));

    return in_array($c, inboxCostCategoryKeys(), true) ? $c : 'magazyn';
}

/** Koszt poza magazynem — akceptacja bez PZ. */
function inboxCostCategoryIsOverhead(string $category): bool
{
    return $category !== '' && $category !== 'magazyn';
}

/** Czy migracja 057 (line_type / expense_category_id) jest na bazie. */
function inboxKsefLineHasOpexColumns(PDO $pdo): bool
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    try {
        $db = (string) ($pdo->query('SELECT DATABASE()')->fetchColumn() ?: '');
        if ($db === '') {
            $cache = false;

            return false;
        }
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :tn AND COLUMN_NAME = :cn'
        );
        $st->execute([':db' => $db, ':tn' => 'sh_ksef_invoice_lines', ':cn' => 'line_type']);
        $cache = ((int) $st->fetchColumn()) > 0;
    } catch (\Throwable $e) {
        $cache = false;
    }

    return $cache;
}

function inboxExpenseCategoryValid(PDO $pdo, int $tenantId, int $categoryId): bool
{
    if ($categoryId <= 0) {
        return false;
    }
    $st = $pdo->prepare(
        "SELECT 1 FROM sh_expense_categories
          WHERE id = :id AND tenant_id = :tid AND is_deleted = 0 AND is_active = 1 LIMIT 1"
    );
    $st->execute([':id' => $categoryId, ':tid' => $tenantId]);

    return (bool) $st->fetchColumn();
}

/** Znajdź istniejącą fakturę (ten sam tenant + numer FA + NIP dostawcy — cyfry). */
function inboxFindDuplicateInvoice(PDO $pdo, int $tenantId, string $invoiceNumber, string $supplierNipDigits): ?array
{
    $invoiceNumber = trim($invoiceNumber);
    if ($invoiceNumber === '') {
        return null;
    }
    $st = $pdo->prepare(
        "SELECT id, status, supplier_nip, invoice_number, ksef_reference_id, linked_wh_document_id, fetched_at
           FROM sh_ksef_invoices
          WHERE tenant_id = :tid AND invoice_number = :inum
          ORDER BY id DESC"
    );
    $st->execute([':tid' => $tenantId, ':inum' => $invoiceNumber]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $row) {
        $rowSn = preg_replace('/\D+/', '', (string) ($row['supplier_nip'] ?? '')) ?? '';
        if ($rowSn === $supplierNipDigits) {
            return $row;
        }
    }
    return null;
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
    $invOnly = inboxKsefLineHasOpexColumns($pdo)
        ? " AND line_type = 'INVENTORY' "
        : '';
    $st = $pdo->prepare(
        "SELECT id, line_no, external_name FROM sh_ksef_invoice_lines
          WHERE ksef_invoice_id = :iid {$invOnly} ORDER BY line_no"
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
    require_once __DIR__ . '/../../core/Ksef/InboxInvoiceRepository.php';
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
                           sale_date, total_gross_minor, status, cost_category, status_message,
                           linked_wh_document_id, fetched_at, processed_at
                      FROM sh_ksef_invoices
                     WHERE tenant_id = :tid";
            $params = [':tid' => $tenant_id];
            if ($status !== '') {
                $sql .= " AND status = :st";
                $params[':st'] = $status;
            }
            $sql .= " ORDER BY CASE status WHEN 'draft' THEN 1 WHEN 'accepted' THEN 2 WHEN 'rejected' THEN 3 ELSE 4 END,
                      COALESCE(issue_date, DATE(fetched_at)) DESC, id DESC
                      LIMIT {$limit}";
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

            $hasOpex = inboxKsefLineHasOpexColumns($pdo);
            if ($hasOpex) {
                $stL = $pdo->prepare(
                    "SELECT l.id, l.line_no, l.external_name, l.external_description, l.gtu_code, l.pkwiu,
                            l.unit, l.qty, l.unit_net, l.line_net_minor, l.vat_rate, l.resolved_sku,
                            l.match_type, l.match_confidence, l.match_candidates_json,
                            l.resolved_at, l.resolved_by_user_id,
                            l.line_type, l.expense_category_id, ec.name AS expense_category_name
                       FROM sh_ksef_invoice_lines l
                  LEFT JOIN sh_expense_categories ec
                         ON ec.id = l.expense_category_id AND ec.tenant_id = :tid_ec
                      WHERE l.ksef_invoice_id = :iid
                   ORDER BY l.line_no"
                );
                $stL->execute([':iid' => $iid, ':tid_ec' => $tenant_id]);
            } else {
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
            }
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
                'invoice'            => $inv,
                'lines'              => $lines,
                'threshold'          => $threshold,
                'line_opex_enabled'  => $hasOpex,
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

            $inum = trim((string) ($parsed['invoice']['number'] ?? ''));
            $supplierNipDigits = preg_replace('/\D+/', '', (string) ($parsed['supplier']['nip'] ?? '')) ?? '';
            $dupRes = strtolower(trim((string) ($input['duplicate_resolution'] ?? '')));

            $existing = inboxFindDuplicateInvoice($pdo, $tenant_id, $inum, $supplierNipDigits);

            if ($existing !== null && $dupRes !== 'replace') {
                $canReplace = ($existing['status'] !== 'accepted')
                    && (empty($existing['linked_wh_document_id']) || (int) $existing['linked_wh_document_id'] === 0);
                http_response_code(409);
                inboxResponse(false, [
                    'duplicate'             => true,
                    'existing_invoice_id'   => (int) $existing['id'],
                    'existing_status'       => (string) ($existing['status'] ?? ''),
                    'invoice_number'        => (string) ($existing['invoice_number'] ?? $inum),
                    'supplier_nip'          => (string) ($existing['supplier_nip'] ?? ''),
                    'ksef_reference_id'     => $existing['ksef_reference_id'],
                    'fetched_at'            => (string) ($existing['fetched_at'] ?? ''),
                    'can_replace'           => $canReplace,
                ],
                    'Ta sama faktura (numer + NIP dostawcy) jest już w inbox-ie. Możesz zastąpić wpis nowym XML albo anulować import.',
                    'DUPLICATE_INVOICE'
                );
            }

            if ($existing !== null && $dupRes === 'replace') {
                if (($existing['status'] ?? '') === 'accepted') {
                    inboxFail(400, 'REPLACE_BLOCKED_ACCEPTED',
                        'Nie można zastąpić faktury w statusie „zaakceptowana”. Odrzuć lub cofnij PZ w magazynie, jeśli to pomyłka.');
                }
                if (!empty($existing['linked_wh_document_id']) && (int) $existing['linked_wh_document_id'] > 0) {
                    inboxFail(400, 'REPLACE_BLOCKED_PZ',
                        'Nie można zastąpić — faktura ma powiązany dokument magazynowy (PZ). Usuń powiązanie przed podmianą.');
                }
                $del = $pdo->prepare('DELETE FROM sh_ksef_invoices WHERE id = :id AND tenant_id = :tid');
                $del->execute([':id' => (int) $existing['id'], ':tid' => $tenant_id]);
                inboxAudit($pdo, $tenant_id, $user_id, 'ksef_upload_replace', (int) $existing['id'], [
                    'replaced_by'    => 'upload_xml',
                    'invoice_number' => $inum,
                    'supplier_nip'   => $parsed['supplier']['nip'] ?? '',
                ]);
            }

            $fallbackNo = 'UPLOAD-' . date('YmdHis');
            try {
                $invoiceId = \SliceHub\Ksef\InboxInvoiceRepository::insertInvoiceWithLines(
                    $pdo,
                    $tenant_id,
                    null,
                    $xml,
                    $parsed,
                    $fallbackNo
                );
            } catch (\Throwable $e) {
                inboxFail(500, 'INSERT_FAILED', 'Zapis faktury padł: ' . $e->getMessage());
            }

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
            if ($iid <= 0 || $lid <= 0) {
                inboxFail(400, 'INVALID_INPUT');
            }

            // Tenant scope + status check
            $own = $pdo->prepare(
                "SELECT i.status FROM sh_ksef_invoices i
                   JOIN sh_ksef_invoice_lines l ON l.ksef_invoice_id = i.id
                  WHERE i.tenant_id = :tid AND i.id = :iid AND l.id = :lid LIMIT 1"
            );
            $own->execute([':tid' => $tenant_id, ':iid' => $iid, ':lid' => $lid]);
            $status = $own->fetchColumn();
            if ($status === false) {
                inboxFail(404, 'NOT_FOUND');
            }
            if ($status === 'accepted') {
                inboxFail(400, 'ALREADY_ACCEPTED');
            }

            if (!inboxKsefLineHasOpexColumns($pdo)) {
                $sku = trim((string) ($input['sku'] ?? ''));
                if ($sku === '') {
                    inboxFail(400, 'INVALID_INPUT');
                }
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

            $lineTypeInput = strtoupper(trim((string) ($input['line_type'] ?? '')));
            if ($lineTypeInput === '' && trim((string) ($input['sku'] ?? '')) !== '') {
                $lineTypeInput = 'INVENTORY';
            }
            if ($lineTypeInput === '' && (int) ($input['expense_category_id'] ?? 0) > 0) {
                $lineTypeInput = 'EXPENSE';
            }
            if (!in_array($lineTypeInput, ['INVENTORY', 'EXPENSE'], true)) {
                inboxFail(400, 'INVALID_LINE_TYPE', 'line_type: INVENTORY lub EXPENSE (albo samo sku / expense_category_id).');
            }
            $lineType = $lineTypeInput;

            if ($lineType === 'INVENTORY') {
                $sku = trim((string) ($input['sku'] ?? ''));
                if ($sku === '') {
                    inboxFail(400, 'INVALID_INPUT', 'Dla INVENTORY wymagane jest pole sku.');
                }
                $skuCheck = $pdo->prepare(
                    "SELECT 1 FROM sys_items WHERE tenant_id = :tid AND sku = :sku AND is_deleted = 0 AND is_active = 1 LIMIT 1"
                );
                $skuCheck->execute([':tid' => $tenant_id, ':sku' => $sku]);
                if (!$skuCheck->fetchColumn()) {
                    inboxFail(400, 'INVALID_SKU', "SKU '{$sku}' nie istnieje w sys_items.");
                }
                $upd = $pdo->prepare(
                    "UPDATE sh_ksef_invoice_lines
                        SET line_type = 'INVENTORY',
                            expense_category_id = NULL,
                            resolved_sku = :sku,
                            match_type = 'MANUAL',
                            match_confidence = 100,
                            resolved_at = NOW(),
                            resolved_by_user_id = :uid
                      WHERE id = :lid AND ksef_invoice_id = :iid"
                );
                $upd->execute([':sku' => $sku, ':uid' => $user_id, ':lid' => $lid, ':iid' => $iid]);
                inboxResponse(true, [
                    'line_id' => $lid, 'line_type' => 'INVENTORY', 'sku' => $sku,
                ], 'Linia zaktualizowana (magazyn).');
                break;
            }

            $ecid = (int) ($input['expense_category_id'] ?? 0);
            if ($ecid <= 0 || !inboxExpenseCategoryValid($pdo, $tenant_id, $ecid)) {
                inboxFail(400, 'INVALID_EXPENSE_CATEGORY', 'Wybierz aktywną kategorię kosztu OPEX.');
            }
            $upd = $pdo->prepare(
                "UPDATE sh_ksef_invoice_lines
                    SET line_type = 'EXPENSE',
                        expense_category_id = :ecid,
                        resolved_sku = NULL,
                        match_type = NULL,
                        match_confidence = NULL,
                        match_candidates_json = NULL,
                        resolved_at = NOW(),
                        resolved_by_user_id = :uid
                  WHERE id = :lid AND ksef_invoice_id = :iid"
            );
            $upd->execute([':ecid' => $ecid, ':uid' => $user_id, ':lid' => $lid, ':iid' => $iid]);
            inboxResponse(true, [
                'line_id' => $lid, 'line_type' => 'EXPENSE', 'expense_category_id' => $ecid,
            ], 'Linia zaktualizowana (koszt OPEX).');
            break;
        }

        // ---------------------------------------------------------------------
        case 'bulk_update_lines': {
            inboxRequireRole($actorRole, ['owner', 'manager']);
            $iid = (int) ($input['invoice_id'] ?? 0);
            $rawIds = $input['line_ids'] ?? null;
            if ($iid <= 0 || !is_array($rawIds)) {
                inboxFail(400, 'INVALID_INPUT', 'Wymagane: invoice_id, line_ids (tablica).');
            }
            $lineIds = [];
            foreach ($rawIds as $x) {
                $v = (int) $x;
                if ($v > 0) {
                    $lineIds[$v] = true;
                }
            }
            $lineIds = array_map('intval', array_keys($lineIds));
            if ($lineIds === []) {
                inboxFail(400, 'INVALID_LINE_IDS', 'Wybierz co najmniej jedną linię.');
            }

            $own = $pdo->prepare(
                'SELECT status FROM sh_ksef_invoices WHERE id = :id AND tenant_id = :tid LIMIT 1'
            );
            $own->execute([':id' => $iid, ':tid' => $tenant_id]);
            $status = $own->fetchColumn();
            if ($status === false) {
                inboxFail(404, 'NOT_FOUND');
            }
            if ($status === 'accepted') {
                inboxFail(400, 'ALREADY_ACCEPTED');
            }

            $placeholders = implode(',', array_fill(0, count($lineIds), '?'));
            $verifySql = "SELECT l.id FROM sh_ksef_invoice_lines l
                INNER JOIN sh_ksef_invoices i ON i.id = l.ksef_invoice_id
                 WHERE i.tenant_id = ?
                   AND l.ksef_invoice_id = ?
                   AND l.id IN ({$placeholders})";
            $verifyParams = array_merge([$tenant_id, $iid], $lineIds);
            $vf = $pdo->prepare($verifySql);
            $vf->execute($verifyParams);
            $okIds = $vf->fetchAll(PDO::FETCH_COLUMN);
            if (count($okIds) !== count($lineIds)) {
                inboxFail(400, 'LINES_MISMATCH', 'Część identyfikatorów linii nie należy do tej faktury.');
            }

            if (!inboxKsefLineHasOpexColumns($pdo)) {
                $sku = trim((string) ($input['sku'] ?? ''));
                if ($sku === '') {
                    inboxFail(400, 'INVALID_INPUT', 'Wymagane pole sku dla edycji grupowej (tryb legacy).');
                }
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
                      WHERE id = :lid AND ksef_invoice_id = :iid"
                );
                foreach ($lineIds as $lid) {
                    $upd->execute([':sku' => $sku, ':uid' => $user_id, ':lid' => $lid, ':iid' => $iid]);
                }
                $n = count($lineIds);
                inboxResponse(true, ['updated' => $n, 'sku' => $sku],
                    "Zaktualizowano {$n} " . ($n === 1 ? 'linię' : 'linii') . ' (SKU).');
                break;
            }

            $lineTypeInput = strtoupper(trim((string) ($input['line_type'] ?? '')));
            if ($lineTypeInput === '' && trim((string) ($input['sku'] ?? '')) !== '') {
                $lineTypeInput = 'INVENTORY';
            }
            if ($lineTypeInput === '' && (int) ($input['expense_category_id'] ?? 0) > 0) {
                $lineTypeInput = 'EXPENSE';
            }
            if (!in_array($lineTypeInput, ['INVENTORY', 'EXPENSE'], true)) {
                inboxFail(400, 'INVALID_LINE_TYPE', 'bulk: podaj line_type lub sku albo expense_category_id.');
            }

            if ($lineTypeInput === 'INVENTORY') {
                $sku = trim((string) ($input['sku'] ?? ''));
                if ($sku === '') {
                    inboxFail(400, 'INVALID_INPUT', 'Dla INVENTORY wymagane jest pole sku.');
                }
                $skuCheck = $pdo->prepare(
                    "SELECT 1 FROM sys_items WHERE tenant_id = :tid AND sku = :sku AND is_deleted = 0 AND is_active = 1 LIMIT 1"
                );
                $skuCheck->execute([':tid' => $tenant_id, ':sku' => $sku]);
                if (!$skuCheck->fetchColumn()) {
                    inboxFail(400, 'INVALID_SKU', "SKU '{$sku}' nie istnieje w sys_items.");
                }
                $upd = $pdo->prepare(
                    "UPDATE sh_ksef_invoice_lines
                        SET line_type = 'INVENTORY',
                            expense_category_id = NULL,
                            resolved_sku = :sku,
                            match_type = 'MANUAL',
                            match_confidence = 100,
                            resolved_at = NOW(),
                            resolved_by_user_id = :uid
                      WHERE id = :lid AND ksef_invoice_id = :iid"
                );
                foreach ($lineIds as $lid) {
                    $upd->execute([':sku' => $sku, ':uid' => $user_id, ':lid' => $lid, ':iid' => $iid]);
                }
                $n = count($lineIds);
                inboxResponse(true, [
                    'updated' => $n, 'line_type' => 'INVENTORY', 'sku' => $sku,
                ], "Zaktualizowano {$n} " . ($n === 1 ? 'linię' : 'linii') . ' (magazyn).');
                break;
            }

            $ecid = (int) ($input['expense_category_id'] ?? 0);
            if ($ecid <= 0 || !inboxExpenseCategoryValid($pdo, $tenant_id, $ecid)) {
                inboxFail(400, 'INVALID_EXPENSE_CATEGORY', 'Wybierz aktywną kategorię kosztu OPEX.');
            }
            $upd = $pdo->prepare(
                "UPDATE sh_ksef_invoice_lines
                    SET line_type = 'EXPENSE',
                        expense_category_id = :ecid,
                        resolved_sku = NULL,
                        match_type = NULL,
                        match_confidence = NULL,
                        match_candidates_json = NULL,
                        resolved_at = NOW(),
                        resolved_by_user_id = :uid
                  WHERE id = :lid AND ksef_invoice_id = :iid"
            );
            foreach ($lineIds as $lid) {
                $upd->execute([':ecid' => $ecid, ':uid' => $user_id, ':lid' => $lid, ':iid' => $iid]);
            }
            $n = count($lineIds);
            inboxResponse(true, [
                'updated' => $n, 'line_type' => 'EXPENSE', 'expense_category_id' => $ecid,
            ], "Zaktualizowano {$n} " . ($n === 1 ? 'linię' : 'linii') . ' (OPEX).');
            break;
        }

        // ---------------------------------------------------------------------
        case 'set_cost_category': {
            inboxRequireRole($actorRole, ['owner', 'manager']);
            if (inboxKsefLineHasOpexColumns($pdo)) {
                inboxFail(400, 'USE_LINE_OPEX',
                    'Ta instancja używa typów linii (INVENTORY / EXPENSE). Ustaw kategorię OPEX per linia zamiast nagłówka.');
            }
            $iid = (int) ($input['invoice_id'] ?? 0);
            $cat = inboxSanitizeCostCategory($input['cost_category'] ?? 'magazyn');
            if ($iid <= 0) inboxFail(400, 'INVALID_INVOICE_ID');

            $st = $pdo->prepare("SELECT status FROM sh_ksef_invoices WHERE id = :id AND tenant_id = :tid LIMIT 1");
            $st->execute([':id' => $iid, ':tid' => $tenant_id]);
            $status = $st->fetchColumn();
            if ($status === false) inboxFail(404, 'NOT_FOUND');
            if (!in_array((string) $status, ['draft', 'error'], true)) {
                inboxFail(400, 'BAD_STATUS', 'Kategorię można zmienić tylko dla faktur w statusie „Nowe” lub „Błąd”.');
            }

            $pdo->prepare(
                "UPDATE sh_ksef_invoices SET cost_category = :cc WHERE id = :id AND tenant_id = :tid"
            )->execute([':cc' => $cat, ':id' => $iid, ':tid' => $tenant_id]);

            inboxResponse(true, ['invoice_id' => $iid, 'cost_category' => $cat], 'Kategoria kosztu zapisana.');
            break;
        }

        // ---------------------------------------------------------------------
        case 'accept': {
            inboxRequireRole($actorRole, ['owner', 'manager']);
            $iid = (int) ($input['invoice_id'] ?? 0);
            $warehouseId = trim((string) ($input['warehouse_id'] ?? 'MAIN'));
            if ($iid <= 0) {
                inboxFail(400, 'INVALID_INVOICE_ID');
            }

            $inv = $pdo->prepare("SELECT * FROM sh_ksef_invoices WHERE id = :id AND tenant_id = :tid LIMIT 1");
            $inv->execute([':id' => $iid, ':tid' => $tenant_id]);
            $invoice = $inv->fetch(PDO::FETCH_ASSOC);
            if (!$invoice) {
                inboxFail(404, 'NOT_FOUND');
            }
            if ($invoice['status'] === 'accepted') {
                inboxFail(400, 'ALREADY_ACCEPTED');
            }

            if (inboxKsefLineHasOpexColumns($pdo)) {
                $linesSt = $pdo->prepare(
                    "SELECT id, line_no, external_name, qty, unit_net, vat_rate, resolved_sku, match_confidence,
                            COALESCE(line_type, 'INVENTORY') AS line_type, expense_category_id
                       FROM sh_ksef_invoice_lines WHERE ksef_invoice_id = :iid ORDER BY line_no"
                );
                $linesSt->execute([':iid' => $iid]);
                $lines = $linesSt->fetchAll(PDO::FETCH_ASSOC);

                foreach ($lines as $l) {
                    $lt = strtoupper((string) ($l['line_type'] ?? 'INVENTORY'));
                    if (!in_array($lt, ['INVENTORY', 'EXPENSE'], true)) {
                        $lt = 'INVENTORY';
                    }
                    if ($lt === 'EXPENSE') {
                        $eid = (int) ($l['expense_category_id'] ?? 0);
                        if ($eid <= 0 || !inboxExpenseCategoryValid($pdo, $tenant_id, $eid)) {
                            inboxFail(400, 'EXPENSE_CATEGORY_REQUIRED',
                                'Linia ' . (int) $l['line_no'] . ' (EXPENSE): wybierz kategorię kosztu OPEX.');
                        }
                    } elseif (empty($l['resolved_sku'])) {
                        inboxFail(400, 'UNRESOLVED_LINES',
                            'Linia ' . (int) $l['line_no'] . ' (INVENTORY): brak SKU — dopasuj lub zmień typ na EXPENSE.');
                    }
                }

                $pzLines = [];
                foreach ($lines as $l) {
                    $lt = strtoupper((string) ($l['line_type'] ?? 'INVENTORY'));
                    if ($lt === 'EXPENSE') {
                        continue;
                    }
                    $pzLines[] = [
                        'external_name' => $l['external_name'],
                        'resolved_sku'  => $l['resolved_sku'],
                        'quantity'      => (float) $l['qty'],
                        'unit_net_cost' => (float) $l['unit_net'],
                        'vat_rate'      => (float) $l['vat_rate'],
                    ];
                }

                $markExp = $pdo->prepare(
                    "UPDATE sh_ksef_invoice_lines
                        SET line_type = 'EXPENSE',
                            expense_category_id = :ecid,
                            resolved_sku = NULL,
                            match_type = NULL,
                            match_confidence = NULL,
                            match_candidates_json = NULL,
                            resolved_at = NOW(),
                            resolved_by_user_id = :uid
                      WHERE id = :lid AND ksef_invoice_id = :iid"
                );
                $markInv = $pdo->prepare(
                    "UPDATE sh_ksef_invoice_lines
                        SET line_type = 'INVENTORY',
                            expense_category_id = NULL,
                            resolved_at = COALESCE(resolved_at, NOW()),
                            resolved_by_user_id = COALESCE(resolved_by_user_id, :uid)
                      WHERE id = :lid AND ksef_invoice_id = :iid"
                );

                if ($pzLines === []) {
                    foreach ($lines as $l) {
                        $markExp->execute([
                            ':ecid' => (int) $l['expense_category_id'],
                            ':uid'  => $user_id,
                            ':lid'  => (int) $l['id'],
                            ':iid'  => $iid,
                        ]);
                    }
                    $pdo->prepare(
                        "UPDATE sh_ksef_invoices
                            SET status = 'accepted',
                                cost_category = 'inne',
                                linked_wh_document_id = NULL,
                                processed_at = NOW(),
                                processed_by_user_id = :uid
                          WHERE id = :id AND tenant_id = :tid"
                    )->execute([':uid' => $user_id, ':id' => $iid, ':tid' => $tenant_id]);

                    inboxAudit($pdo, $tenant_id, $user_id, 'ksef_accept_opex_only', $iid, [
                        'lines_count'     => count($lines),
                        'inventory_lines' => 0,
                        'expense_lines'   => count($lines),
                    ]);

                    inboxResponse(true, [
                        'invoice_id'          => $iid,
                        'pz_document'         => null,
                        'expense_only_accept' => true,
                        'cost_category'       => 'inne',
                    ], 'Zaakceptowano (100% koszty OPEX — bez PZ).');
                    break;
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

                foreach ($lines as $l) {
                    $lt = strtoupper((string) ($l['line_type'] ?? 'INVENTORY'));
                    if ($lt === 'EXPENSE') {
                        $markExp->execute([
                            ':ecid' => (int) $l['expense_category_id'],
                            ':uid'  => $user_id,
                            ':lid'  => (int) $l['id'],
                            ':iid'  => $iid,
                        ]);
                    } else {
                        $markInv->execute([':uid' => $user_id, ':lid' => (int) $l['id'], ':iid' => $iid]);
                    }
                }

                $pdo->prepare(
                    "UPDATE sh_ksef_invoices
                        SET status = 'accepted',
                            cost_category = 'magazyn',
                            linked_wh_document_id = :pzid,
                            processed_at = NOW(),
                            processed_by_user_id = :uid
                      WHERE id = :id AND tenant_id = :tid"
                )->execute([
                    ':pzid' => $pzDocId,
                    ':uid'  => $user_id,
                    ':id'   => $iid,
                    ':tid'  => $tenant_id,
                ]);

                inboxAudit($pdo, $tenant_id, $user_id, 'ksef_accept', $iid, [
                    'pz_doc_id'       => $pzDocId,
                    'pz_doc_number'   => $pzResult['pz_document']['doc_number'] ?? '',
                    'auto_learned'    => $pzResult['pz_document']['auto_learned'] ?? 0,
                    'pz_lines_count'  => count($pzLines),
                    'invoice_lines'   => count($lines),
                    'line_opex_model' => true,
                ]);

                inboxResponse(true, [
                    'invoice_id'    => $iid,
                    'pz_document'   => $pzResult['pz_document'],
                    'cost_category' => 'magazyn',
                ], 'Zaakceptowane → PZ ' . ($pzResult['pz_document']['doc_number'] ?? '?'));
                break;
            }

            // Legacy: nagłówkowa cost_category (instancje bez kolumn line_type)
            $costCategory = inboxSanitizeCostCategory($input['cost_category'] ?? ($invoice['cost_category'] ?? 'magazyn'));

            $linesSt = $pdo->prepare(
                "SELECT id, line_no, external_name, qty, unit_net, vat_rate, resolved_sku, match_confidence
                   FROM sh_ksef_invoice_lines WHERE ksef_invoice_id = :iid ORDER BY line_no"
            );
            $linesSt->execute([':iid' => $iid]);
            $lines = $linesSt->fetchAll(PDO::FETCH_ASSOC);

            if (!inboxCostCategoryIsOverhead($costCategory)) {
                $unresolved = [];
                foreach ($lines as $l) {
                    if (empty($l['resolved_sku'])) {
                        $unresolved[] = ['line_no' => $l['line_no'], 'external_name' => $l['external_name']];
                    }
                }
                if ($unresolved !== []) {
                    inboxFail(400, 'UNRESOLVED_LINES',
                        'Nie wszystkie linie mają zmapowany SKU (wymagane dla kategorii „Magazyn”). '
                        . 'Zmień „Kategorię kosztu” na np. „Media / energia”, aby zaakceptować bez magazynu, albo dopasuj SKU. '
                        . 'Niedopasowanych linii: ' . count($unresolved));
                }

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

                $pdo->prepare(
                    "UPDATE sh_ksef_invoices
                        SET status = 'accepted',
                            cost_category = :cc,
                            linked_wh_document_id = :pzid,
                            processed_at = NOW(),
                            processed_by_user_id = :uid
                      WHERE id = :id AND tenant_id = :tid"
                )->execute([
                    ':cc' => $costCategory, ':pzid' => $pzDocId,
                    ':uid' => $user_id, ':id' => $iid, ':tid' => $tenant_id,
                ]);

                inboxAudit($pdo, $tenant_id, $user_id, 'ksef_accept', $iid, [
                    'pz_doc_id'     => $pzDocId,
                    'pz_doc_number' => $pzResult['pz_document']['doc_number'] ?? '',
                    'auto_learned'  => $pzResult['pz_document']['auto_learned'] ?? 0,
                    'lines_count'   => count($pzLines),
                    'cost_category' => $costCategory,
                ]);

                inboxResponse(true, [
                    'invoice_id'    => $iid,
                    'pz_document'   => $pzResult['pz_document'],
                    'cost_category' => $costCategory,
                ], 'Zaakceptowane → PZ ' . ($pzResult['pz_document']['doc_number'] ?? '?'));
                break;
            }

            // Koszt operacyjny (media / usługi / inne) — bez PZ, na potrzeby ewidencji i statystyk
            $pdo->prepare(
                "UPDATE sh_ksef_invoices
                    SET status = 'accepted',
                        cost_category = :cc,
                        linked_wh_document_id = NULL,
                        processed_at = NOW(),
                        processed_by_user_id = :uid
                  WHERE id = :id AND tenant_id = :tid"
            )->execute([
                ':cc' => $costCategory, ':uid' => $user_id,
                ':id' => $iid, ':tid' => $tenant_id,
            ]);

            inboxAudit($pdo, $tenant_id, $user_id, 'ksef_accept_cost', $iid, [
                'cost_category' => $costCategory,
                'lines_count'   => count($lines),
            ]);

            inboxResponse(true, [
                'invoice_id'    => $iid,
                'pz_document'   => null,
                'cost_only'     => true,
                'cost_category' => $costCategory,
            ], 'Zaakceptowano jako koszt operacyjny (bez PZ).');
            break;
        }

        // ---------------------------------------------------------------------
        // F4.5: Smart-create — utwórz nowy sys_items + zmapuj do linii faktury
        case 'smart_create_sku': {
            inboxRequireRole($actorRole, ['owner', 'manager']);
            $iid = (int) ($input['invoice_id'] ?? 0);
            $lid = (int) ($input['line_id'] ?? 0);
            $sku = strtoupper(preg_replace('/[^A-Z0-9_]/', '_', strtoupper(trim((string) ($input['sku'] ?? '')))) ?? '');
            $name = trim((string) ($input['name'] ?? ''));
            $unit = trim((string) ($input['unit'] ?? 'kg'));
            if ($iid <= 0 || $lid <= 0 || $sku === '' || $name === '') {
                inboxFail(400, 'INVALID_INPUT', 'Wymagane: invoice_id, line_id, sku, name.');
            }
            if (strlen($sku) > 64) inboxFail(400, 'SKU_TOO_LONG', 'SKU max 64 znaki.');

            // Tenant scope check (linia musi należeć do tenanta przez sh_ksef_invoices)
            if (inboxKsefLineHasOpexColumns($pdo)) {
                $own = $pdo->prepare(
                    "SELECT l.external_name, i.status, COALESCE(l.line_type, 'INVENTORY') AS line_type
                       FROM sh_ksef_invoice_lines l
                       JOIN sh_ksef_invoices i ON i.id = l.ksef_invoice_id
                      WHERE i.tenant_id = :tid AND i.id = :iid AND l.id = :lid LIMIT 1"
                );
            } else {
                $own = $pdo->prepare(
                    "SELECT l.external_name, i.status FROM sh_ksef_invoice_lines l
                       JOIN sh_ksef_invoices i ON i.id = l.ksef_invoice_id
                      WHERE i.tenant_id = :tid AND i.id = :iid AND l.id = :lid LIMIT 1"
                );
            }
            $own->execute([':tid' => $tenant_id, ':iid' => $iid, ':lid' => $lid]);
            $row = $own->fetch(PDO::FETCH_ASSOC);
            if (!$row) inboxFail(404, 'NOT_FOUND');
            if ($row['status'] === 'accepted') inboxFail(400, 'ALREADY_ACCEPTED');
            if (inboxKsefLineHasOpexColumns($pdo) && strtoupper((string) ($row['line_type'] ?? 'INVENTORY')) === 'EXPENSE') {
                inboxFail(400, 'NOT_INVENTORY_LINE', 'Smart-create dotyczy tylko linii magazynowych (INVENTORY).');
            }
            $extName = (string) $row['external_name'];

            $pdo->beginTransaction();
            try {
                // 1. Sprawdź czy SKU już istnieje (idempotent)
                $check = $pdo->prepare("SELECT id FROM sys_items WHERE tenant_id = :tid AND sku = :sku LIMIT 1");
                $check->execute([':tid' => $tenant_id, ':sku' => $sku]);
                $existingId = $check->fetchColumn();
                if (!$existingId) {
                    // INSERT do sys_items
                    $pdo->prepare(
                        "INSERT INTO sys_items (tenant_id, sku, name, base_unit, is_active, is_deleted)
                         VALUES (:tid, :sku, :name, :unit, 1, 0)"
                    )->execute([':tid' => $tenant_id, ':sku' => $sku, ':name' => $name, ':unit' => $unit]);
                }

                // 2. Update linię — sku + match_type='MANUAL'
                if (inboxKsefLineHasOpexColumns($pdo)) {
                    $pdo->prepare(
                        "UPDATE sh_ksef_invoice_lines
                            SET line_type = 'INVENTORY',
                                expense_category_id = NULL,
                                resolved_sku = :sku, match_type = 'MANUAL', match_confidence = 100,
                                resolved_at = NOW(), resolved_by_user_id = :uid
                          WHERE id = :lid"
                    )->execute([':sku' => $sku, ':uid' => $user_id, ':lid' => $lid]);
                } else {
                    $pdo->prepare(
                        "UPDATE sh_ksef_invoice_lines
                            SET resolved_sku = :sku, match_type = 'MANUAL', match_confidence = 100,
                                resolved_at = NOW(), resolved_by_user_id = :uid
                          WHERE id = :lid"
                    )->execute([':sku' => $sku, ':uid' => $user_id, ':lid' => $lid]);
                }

                // 3. Auto-learn do sh_product_mapping (network effect — kolejny PZ z tej nazwy = EXACT)
                $pdo->prepare(
                    "INSERT IGNORE INTO sh_product_mapping (tenant_id, external_name, internal_sku)
                     VALUES (:tid, :ext, :sku)"
                )->execute([':tid' => $tenant_id, ':ext' => $extName, ':sku' => $sku]);

                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                inboxFail(500, 'CREATE_FAILED', 'Smart-create padł: ' . $e->getMessage());
            }

            inboxAudit($pdo, $tenant_id, $user_id, 'ksef_smart_create', $iid, [
                'sku' => $sku, 'name' => $name, 'unit' => $unit, 'external_name' => $extName,
            ]);

            inboxResponse(true, [
                'sku' => $sku, 'name' => $name, 'unit' => $unit,
                'line_id' => $lid, 'external_name' => $extName,
            ], "Utworzono SKU '{$sku}' i przypisano do linii. Network effect: kolejne faktury z '{$extName}' będą EXACT 100%.");
            break;
        }

        // ---------------------------------------------------------------------
        // F4.5: Reverse — wycofaj zaakceptowaną fakturę (status → draft, KOR PZ)
        case 'reverse': {
            inboxRequireRole($actorRole, ['owner']);
            $iid = (int) ($input['invoice_id'] ?? 0);
            $reason = trim((string) ($input['reason'] ?? ''));
            if ($iid <= 0) inboxFail(400, 'INVALID_INVOICE_ID');

            $invSt = $pdo->prepare(
                "SELECT id, status, linked_wh_document_id, cost_category FROM sh_ksef_invoices WHERE id = :id AND tenant_id = :tid LIMIT 1"
            );
            $invSt->execute([':id' => $iid, ':tid' => $tenant_id]);
            $inv = $invSt->fetch(PDO::FETCH_ASSOC);
            if (!$inv) inboxFail(404, 'NOT_FOUND');
            if ($inv['status'] !== 'accepted') {
                inboxFail(400, 'NOT_ACCEPTED', 'Można wycofać tylko zaakceptowane faktury (obecnie: ' . $inv['status'] . ').');
            }

            // Faktura kosztowa bez PZ — cofamy tylko status (brak KOR / magazynu)
            if (empty($inv['linked_wh_document_id'])) {
                $msg = 'Wycofanie akceptacji (koszt bez PZ)' . ($reason ? ': ' . $reason : '');
                $stRev = $pdo->prepare(
                    "UPDATE sh_ksef_invoices
                        SET status = 'draft',
                            linked_wh_document_id = NULL,
                            processed_at = NULL,
                            processed_by_user_id = NULL,
                            status_message = :msg
                      WHERE id = :id AND tenant_id = :tid AND status = 'accepted'"
                );
                $stRev->execute([':msg' => $msg, ':id' => $iid, ':tid' => $tenant_id]);
                if ($stRev->rowCount() === 0) {
                    inboxFail(400, 'REVERSE_FAILED', 'Nie udało się cofnąć akceptacji.');
                }
                inboxAudit($pdo, $tenant_id, $user_id, 'ksef_reverse_cost', $iid, [
                    'reason'         => $reason,
                    'cost_category'  => $inv['cost_category'] ?? 'magazyn',
                ]);
                inboxResponse(true, [
                    'invoice_id' => $iid,
                    'status'     => 'draft',
                    'cost_only'  => true,
                ], 'Faktura wróciła do statusu „Nowe” (bez zmian w magazynie — brak PZ).');
                break;
            }

            $pzDocId = (int) $inv['linked_wh_document_id'];

            // Reverse stocks z wh_documents PZ — wyciągnij linie i odwróć
            // (proste podejście — bez KorEngine bo on jest dla zwrotów do dostawcy
            //  z dokumentu WZ, tu mamy PZ. Robimy bezpośredni reverse w transakcji.)
            $pdo->beginTransaction();
            try {
                $linesSt = $pdo->prepare(
                    "SELECT sku, quantity, unit_net_cost, old_avco FROM wh_document_lines WHERE document_id = :did"
                );
                $linesSt->execute([':did' => $pzDocId]);
                $pzLines = $linesSt->fetchAll(PDO::FETCH_ASSOC);
                if ($pzLines === []) {
                    throw new \RuntimeException('PZ document ma 0 linii — nic do reverse.');
                }

                // Tworzymy KOR-PZ dokument
                $korSt = $pdo->prepare(
                    "INSERT INTO wh_documents
                        (tenant_id, doc_number, type, warehouse_id, references_wz, status, notes, created_by)
                     VALUES (:tid, '', 'KOR', (SELECT warehouse_id FROM wh_documents WHERE id=:did2),
                             :ref, 'completed', :notes, :uid)"
                );
                $pzDocNumberSt = $pdo->prepare("SELECT doc_number, warehouse_id FROM wh_documents WHERE id = :did");
                $pzDocNumberSt->execute([':did' => $pzDocId]);
                $pzMeta = $pzDocNumberSt->fetch(PDO::FETCH_ASSOC);
                $korSt->execute([
                    ':tid'   => $tenant_id,
                    ':did2'  => $pzDocId,
                    ':ref'   => $pzMeta['doc_number'] ?? '',
                    ':notes' => 'Reverse KSeF invoice #' . $iid . ($reason ? ' — ' . $reason : ''),
                    ':uid'   => $user_id,
                ]);
                $korId = (int) $pdo->lastInsertId();
                $korNumber = sprintf('KOR/%s/%05d', date('Y/m/d'), $korId);
                $pdo->prepare("UPDATE wh_documents SET doc_number = :dn WHERE id = :id")
                    ->execute([':dn' => $korNumber, ':id' => $korId]);

                $korLineSt = $pdo->prepare(
                    "INSERT INTO wh_document_lines
                        (document_id, sku, quantity, unit_net_cost, line_net_value, vat_rate, old_avco, new_avco)
                     VALUES (:did, :sku, :qty, :unc, :lnv, 0, :oa, :na)"
                );
                $updStock = $pdo->prepare(
                    "UPDATE wh_stock SET quantity = quantity - :qty
                      WHERE tenant_id = :tid AND warehouse_id = :wid AND sku = :sku"
                );
                $stockLog = $pdo->prepare(
                    "INSERT INTO wh_stock_logs
                        (tenant_id, warehouse_id, sku, change_qty, after_qty, document_type, document_id, created_by)
                     VALUES (:tid, :wid, :sku, :chg, :after, 'KOR', :did, :uid)"
                );

                foreach ($pzLines as $pzL) {
                    $sku = (string) $pzL['sku'];
                    $qty = (float) $pzL['quantity'];

                    // Negative line w KOR
                    $korLineSt->execute([
                        ':did' => $korId, ':sku' => $sku,
                        ':qty' => -$qty, ':unc' => $pzL['unit_net_cost'],
                        ':lnv' => -1 * (float) $pzL['unit_net_cost'] * $qty,
                        ':oa'  => $pzL['old_avco'], ':na' => $pzL['old_avco'],
                    ]);
                    // Update stock
                    $updStock->execute([
                        ':qty' => $qty, ':tid' => $tenant_id,
                        ':wid' => $pzMeta['warehouse_id'], ':sku' => $sku,
                    ]);
                    // Log
                    $currentQty = (float) ($pdo->query("SELECT quantity FROM wh_stock WHERE tenant_id={$tenant_id}
                        AND warehouse_id='" . addslashes($pzMeta['warehouse_id']) . "' AND sku='" . addslashes($sku) . "'")
                        ->fetchColumn() ?: 0);
                    $stockLog->execute([
                        ':tid' => $tenant_id, ':wid' => $pzMeta['warehouse_id'], ':sku' => $sku,
                        ':chg' => -$qty, ':after' => $currentQty, ':did' => $korId, ':uid' => $user_id,
                    ]);
                }

                // Update sh_ksef_invoices: status='draft', clear linked_wh_document_id
                $pdo->prepare(
                    "UPDATE sh_ksef_invoices
                        SET status = 'draft', linked_wh_document_id = NULL,
                            processed_at = NULL, processed_by_user_id = NULL,
                            status_message = :msg
                      WHERE id = :id AND tenant_id = :tid"
                )->execute([
                    ':msg' => 'Zwrócona z accepted przez ' . $actorRole . '. KOR doc: ' . $korNumber . ($reason ? ' — ' . $reason : ''),
                    ':id'  => $iid, ':tid' => $tenant_id,
                ]);

                $pdo->commit();

                inboxAudit($pdo, $tenant_id, $user_id, 'ksef_reverse', $iid, [
                    'pz_doc_id'  => $pzDocId,
                    'kor_doc_id' => $korId,
                    'kor_doc_number' => $korNumber,
                    'reason'     => $reason,
                ]);

                inboxResponse(true, [
                    'invoice_id'     => $iid,
                    'kor_doc_id'     => $korId,
                    'kor_doc_number' => $korNumber,
                    'status'         => 'draft',
                ], "Wycofano: utworzony KOR {$korNumber}, magazyn pomniejszony. Faktura wraca do statusu 'draft'.");
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                inboxFail(500, 'REVERSE_FAILED', 'Reverse padł: ' . $e->getMessage());
            }
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
