<?php

declare(strict_types=1);

require_once __DIR__ . '/JwtProvider.php';

/**
 * Stateless JWT middleware — require at the top of protected API scripts.
 */
class AuthGuard
{
    /**
     * Validates Authorization: Bearer … and returns the JWT payload.
     *
     * @return array<string, mixed>
     */
    public static function protect(string $jwtSecret): array
    {
        header('Content-Type: application/json; charset=utf-8');

        $raw = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!is_string($raw) || $raw === '') {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }

        if (!preg_match('/^\s*Bearer\s+(\S+)\s*$/i', $raw, $m)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }

        $token = $m[1];
        if ($token === '') {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }

        try {
            $payload = JwtProvider::decode($token, $jwtSecret);
        } catch (\Throwable) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }

        if (!isset($payload['tenant_id'], $payload['user_id'])) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }

        $tid = $payload['tenant_id'];
        $uid = $payload['user_id'];
        if ($tid === '' || $tid === null || (is_numeric($tid) && (int)$tid <= 0)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }
        if ($uid === '' || $uid === null || (is_numeric($uid) && (int)$uid <= 0)) {
            http_response_code(401);
            echo json_encode(['success' => false, 'message' => 'Unauthorized']);
            exit;
        }

        return $payload;
    }
}
