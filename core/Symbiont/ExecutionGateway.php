<?php
declare(strict_types=1);

namespace SliceHub\Symbiont;

require_once __DIR__ . '/SessionAuthority.php';

final class ExecutionGateway
{
    private array $config;
    private array $contract;
    private array $catalog;
    private SessionAuthority $sessions;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->contract = json_decode(file_get_contents(__DIR__ . '/contracts/capability-execute-v1.json'), true, 32, JSON_THROW_ON_ERROR);
        $this->catalog = json_decode(file_get_contents($this->config['businessCatalogPath']), true, 32, JSON_THROW_ON_ERROR);
        $this->sessions = new SessionAuthority(SessionAuthority::environmentConfig());
    }

    public static function environmentConfig(): array
    {
        return [
            'enabled' => getenv('SYMBIONT_EXECUTE_ENABLED') === '1',
            'serviceToken' => (string)getenv('SYMBIONT_EXECUTE_SERVICE_TOKEN'),
            'sessionServiceToken' => (string)getenv('SYMBIONT_SESSION_SERVICE_TOKEN'),
            'hostId' => (string)getenv('SYMBIONT_BRIDGE_HOST_ID'),
            'environment' => (string)getenv('SYMBIONT_BRIDGE_ENVIRONMENT'),
            'businessCatalogPath' => (string)getenv('SYMBIONT_BUSINESS_CATALOG_FILE'),
            'ordersFixturePath' => (string)getenv('SYMBIONT_ORDERS_FIXTURE_FILE'),
        ];
    }

    public function handle(string $method, string $contentType, string $authorization, string $raw, bool $secureTransport): array
    {
        $id = null;
        if (!$secureTransport) return $this->failure(403, 'UNAUTHORIZED', $id);
        if (empty($this->config['enabled']) || !$this->validToken($this->config['serviceToken']) || !$this->validToken($this->config['sessionServiceToken']) || !$this->validStringPath($this->config['businessCatalogPath']) || !$this->validStringPath($this->config['ordersFixturePath']) || !preg_match('/\A[a-z0-9][a-z0-9-]{1,62}[a-z0-9]\z/D', $this->config['hostId']) || !in_array($this->config['environment'], ['development', 'test', 'staging', 'production'], true)) {
            return $this->failure(503, 'DISABLED', $id);
        }
        if (!str_starts_with($authorization, 'Bearer ') || !hash_equals($this->config['serviceToken'], substr($authorization, 7))) {
            return $this->failure(401, 'UNAUTHORIZED', $id);
        }
        if ($method !== 'POST' || strtolower(trim(explode(';', $contentType)[0])) !== 'application/json' || strlen($raw) > 4096) {
            return $this->failure(400, 'INVALID_REQUEST', $id);
        }
        try { $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); } catch (\Throwable) { return $this->failure(400, 'INVALID_REQUEST', $id); }
        if (!is_array($input)) return $this->failure(400, 'INVALID_REQUEST', $id);
        $provisional = (string)($input['request_id'] ?? '');
        if (!preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/D', $provisional)) return $this->failure(400, 'INVALID_REQUEST', null);
        $id = $provisional;
        if ($input['protocol'] !== $this->contract['protocol'] || $input['version'] !== $this->contract['version']) {
            return $this->failure(409, 'UNSUPPORTED_VERSION', $id);
        }
        if ($input['operation'] !== 'capability.execute') return $this->failure(422, 'UNSUPPORTED_OPERATION', $id);
        $allowed = ['protocol', 'version', 'request_id', 'operation', 'capability_id', 'session_token', 'tenant_id', 'parameters'];
        foreach (array_keys($input) as $k) if (!in_array($k, $allowed, true)) return $this->failure(400, 'INVALID_REQUEST', $id);
        foreach (['protocol', 'version', 'request_id', 'operation', 'capability_id', 'session_token', 'tenant_id'] as $k) if (!array_key_exists($k, $input)) return $this->failure(400, 'INVALID_REQUEST', $id);
        $capabilityId = is_string($input['capability_id']) ? $input['capability_id'] : '';
        if ($capabilityId === '' || strlen($capabilityId) > 128 || !preg_match('/\A[a-z0-9.-]+\z/D', $capabilityId)) return $this->failure(400, 'INVALID_REQUEST', $id);
        $sessionToken = is_string($input['session_token']) ? $input['session_token'] : '';
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $sessionToken)) return $this->failure(401, 'SESSION_EXPIRED', $id);
        $tenantId = is_int($input['tenant_id']) ? $input['tenant_id'] : (is_string($input['tenant_id']) && ctype_digit($input['tenant_id']) ? (int)$input['tenant_id'] : 0);
        if ($tenantId < 1 || $tenantId > 9999) return $this->failure(400, 'INVALID_TENANT', $id);
        $capability = $this->catalog['capabilities'][$capabilityId] ?? null;
        if (!is_array($capability)) return $this->failure(422, 'INVALID_CAPABILITY', $id);
        if (($capability['execution'] ?? '') !== 'permitted') return $this->failure(403, 'EXECUTION_NOT_PERMITTED', $id);
        if (($capability['business_effects'] ?? '') !== 'read') return $this->failure(403, 'BUSINESS_EFFECT_NOT_ALLOWED', $id);
        [$sessionCode, $sessionResponse] = $this->sessions->handle('POST', 'application/json', 'Bearer ' . $this->config['sessionServiceToken'], json_encode(['protocol' => 'symbiont.operator-session', 'version' => '1.0.0', 'request_id' => $id, 'operation' => 'session.verify', 'session_token' => $sessionToken], JSON_THROW_ON_ERROR), 'service', true);
        if (!is_array($sessionResponse) || empty($sessionResponse['ok'])) {
            return [$sessionCode, ['protocol' => $this->contract['protocol'], 'version' => $this->contract['version'], 'request_id' => $id, 'ok' => false, 'error' => $sessionResponse['error'] ?? 'SESSION_EXPIRED']];
        }
        $session = $sessionResponse['data'] ?? [];
        if (($session['tenant_id'] ?? 0) !== $tenantId) return $this->failure(403, 'INVALID_TENANT', $id);
        if (($session['role'] ?? '') !== ($capability['required_role'] ?? '')) return $this->failure(403, 'INVALID_ROLE', $id);
        try {
            $fixture = json_decode(file_get_contents($this->config['ordersFixturePath']), true, 16, JSON_THROW_ON_ERROR);
        } catch (\Throwable) { return $this->failure(500, 'FIXTURE_ERROR', $id); }
        $orders = [];
        foreach ($fixture['orders'] ?? [] as $order) {
            if (is_array($order) && ($order['tenant_id'] ?? 0) === $tenantId && ($order['status'] ?? '') === 'open') $orders[] = $order;
        }
        return [200, ['protocol' => $this->contract['protocol'], 'version' => $this->contract['version'], 'request_id' => $id, 'ok' => true, 'data' => ['capability_id' => $capabilityId, 'business_effects' => 'read', 'result' => ['orders' => $orders]]]];
    }

    private function failure(int $code, string $error, ?string $id): array
    {
        return [$code, ['protocol' => $this->contract['protocol'], 'version' => $this->contract['version'], 'request_id' => $id ?? '', 'ok' => false, 'error' => $error]];
    }

    private function validToken(string $token): bool
    {
        return (bool)preg_match('/\A[0-9a-f]{64}\z/iD', $token);
    }

    private function validStringPath(string $path): bool
    {
        return $path !== '' && file_exists($path);
    }
}
