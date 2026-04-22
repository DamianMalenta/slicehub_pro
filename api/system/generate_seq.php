<?php

declare(strict_types=1);

// =============================================================================
// STATUS: UTILITY (audit 2026-04-19) — thin HTTP wrapper around SequenceEngine.
// Currently no frontend caller; kept for admin_hub / debug tooling (Faza 3) and
// any external integration that needs to pre-reserve a doc number. Keep.
// =============================================================================
// SliceHub — GET /api/system/generate_seq.php
// Atomic sequence / document number (Section 28).
// Query: doc_type (required), business_date (optional, Y-m-d).
// =============================================================================

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed. Use GET.']);
    exit;
}

try {
    require_once __DIR__ . '/../../core/db_config.php';
    require_once __DIR__ . '/../../core/AuthGuard.php';
    require_once __DIR__ . '/../../core/SequenceEngine.php';

    if (!isset($pdo)) {
        throw new RuntimeException('Database connection unavailable.');
    }

    $payload   = AuthGuard::protect(JWT_SECRET);
    $tenantId  = (int) $payload['tenant_id'];
    $docType   = strtoupper(trim((string) ($_GET['doc_type'] ?? '')));
    $rawDate   = isset($_GET['business_date']) ? trim((string) $_GET['business_date']) : '';
    $bizDate   = $rawDate !== '' ? $rawDate : null;

    if ($docType === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Missing required parameter: doc_type.']);
        exit;
    }

    $data = SequenceEngine::generate($pdo, $tenantId, $docType, $bizDate);

    echo json_encode(
        ['success' => true, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error. Please try again.']);
    error_log('[generate_seq] PDOException: ' . $e->getMessage());
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Internal server error.']);
    error_log('[generate_seq] ' . $e->getMessage());
}

exit;
