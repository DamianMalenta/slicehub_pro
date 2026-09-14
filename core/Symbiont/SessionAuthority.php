<?php
declare(strict_types=1);
namespace SliceHub\Symbiont;

final class SessionAuthority
{
    private array $contract;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->contract = json_decode(file_get_contents(__DIR__ . '/contracts/operator-session-v1.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    public static function environmentConfig(): array
    {
        return [
            'enabled' => getenv('SYMBIONT_SESSION_ENABLED') === '1',
            'serviceToken' => (string)getenv('SYMBIONT_SESSION_SERVICE_TOKEN'),
            'hostId' => (string)getenv('SYMBIONT_BRIDGE_HOST_ID'),
            'environment' => (string)getenv('SYMBIONT_BRIDGE_ENVIRONMENT'),
            'fixturePath' => (string)getenv('SYMBIONT_SESSION_FIXTURE'),
            'storePath' => (string)getenv('SYMBIONT_SESSION_STORE'),
            'ttl' => (int)getenv('SYMBIONT_SESSION_TTL'),
        ];
    }

    public function handle(string $method, string $contentType, string $authorization, string $raw, string $audience, bool $secureTransport): array
    {
        $id = null;
        if (!$secureTransport) return $this->failure(403, 'UNAUTHORIZED', $id);
        if (empty($this->config['enabled']) || !$this->validToken($this->config['serviceToken']) || !$this->validStringPath($this->config['fixturePath']) || !$this->validStringPath($this->config['storePath']) || $this->config['ttl'] < 1 || !preg_match('/\A[a-z0-9][a-z0-9-]{1,62}[a-z0-9]\z/D', $this->config['hostId']) || !in_array($this->config['environment'], ['development', 'test', 'staging', 'production'], true)) {
            return $this->failure(503, 'DISABLED', $id);
        }
        if ($audience === 'service' && (!str_starts_with($authorization, 'Bearer ') || !hash_equals($this->config['serviceToken'], substr($authorization, 7)))) {
            return $this->failure(401, 'UNAUTHORIZED', $id);
        }
        if ($method !== 'POST' || strtolower(trim(explode(';', $contentType)[0])) !== 'application/json' || strlen($raw) > $this->contract['limits']['request_bytes']) {
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
        $op = $input['operation'];
        if (!in_array($op, $this->contract['operations'], true)) return $this->failure(422, 'UNSUPPORTED_OPERATION', $id);
        $fields = match ($op) {
            'session.create' => $this->contract['create_fields'],
            'session.verify' => $this->contract['verify_fields'],
            'session.end' => $this->contract['end_fields'],
            default => [],
        };
        if (!$this->exactKeys($input, $fields)) return $this->failure(400, 'INVALID_REQUEST', $id);
        return match ($op) {
            'session.create' => $this->create($input, $id),
            'session.verify' => $this->verify($input, $id),
            'session.end' => $this->end($input, $id),
        };
    }

    private function create(array $input, string $id): array
    {
        $tenantId = is_int($input['tenant_id']) ? $input['tenant_id'] : (is_string($input['tenant_id']) && ctype_digit($input['tenant_id']) ? (int)$input['tenant_id'] : 0);
        if ($tenantId < 1 || $tenantId > 9999) return $this->failure(400, 'INVALID_TENANT', $id);
        $actorId = is_string($input['actor_id']) ? $input['actor_id'] : '';
        if ($actorId === '' || strlen($actorId) > 80 || !preg_match('/\A[A-Za-z0-9_.-]+\z/D', $actorId)) return $this->failure(400, 'INVALID_REQUEST', $id);
        $pinCode = is_string($input['pin_code']) ? $input['pin_code'] : '';
        $pinMin = (int)($this->contract['limits']['pin_min_length'] ?? 4);
        $pinMax = (int)($this->contract['limits']['pin_max_length'] ?? 8);
        if (!preg_match('/\A[0-9]{' . $pinMin . ',' . $pinMax . '}\z/D', $pinCode)) return $this->failure(400, 'INVALID_REQUEST', $id);
        $role = is_string($input['role']) ? $input['role'] : '';
        if (!in_array($role, $this->contract['roles'], true)) return $this->failure(400, 'INVALID_ROLE', $id);
        $fixture = $this->loadFixture();
        $found = false;
        foreach ($fixture['operators'] ?? [] as $op) {
            if (is_array($op) && ($op['tenant_id'] ?? null) === $tenantId && ($op['actor_id'] ?? '') === $actorId && ($op['pin_code'] ?? '') === $pinCode && ($op['role'] ?? '') === $role) {
                $found = true;
                break;
            }
        }
        if (!$found) return $this->failure(401, 'INVALID_CREDENTIALS', $id);
        $token = bin2hex(random_bytes(32));
        $expires = time() + $this->config['ttl'];
        $store = $this->loadStore();
        $store['sessions'][$token] = ['tenant_id' => $tenantId, 'actor_id' => $actorId, 'role' => $role, 'expires_at' => gmdate('c', $expires)];
        $this->saveStore($store);
        return [200, ['protocol' => $this->contract['protocol'], 'version' => $this->contract['version'], 'request_id' => $id, 'ok' => true, 'data' => ['session_token' => $token, 'tenant_id' => $tenantId, 'actor_id' => $actorId, 'role' => $role, 'expires_at' => gmdate('c', $expires)]]];
    }

    private function verify(array $input, string $id): array
    {
        $token = is_string($input['session_token']) ? $input['session_token'] : '';
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) return $this->failure(401, 'SESSION_EXPIRED', $id);
        $store = $this->loadStore();
        $session = $store['sessions'][$token] ?? null;
        if (!is_array($session) || !isset($session['expires_at'])) return $this->failure(401, 'SESSION_EXPIRED', $id);
        if (strtotime($session['expires_at']) <= time()) {
            unset($store['sessions'][$token]);
            $this->saveStore($store);
            return $this->failure(401, 'SESSION_EXPIRED', $id);
        }
        return [200, ['protocol' => $this->contract['protocol'], 'version' => $this->contract['version'], 'request_id' => $id, 'ok' => true, 'data' => ['session_token' => $token, 'tenant_id' => $session['tenant_id'], 'actor_id' => $session['actor_id'], 'role' => $session['role'], 'expires_at' => $session['expires_at']]]];
    }

    private function end(array $input, string $id): array
    {
        $token = is_string($input['session_token']) ? $input['session_token'] : '';
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $token)) return $this->failure(401, 'SESSION_REVOKED', $id);
        $store = $this->loadStore();
        if (!isset($store['sessions'][$token])) return $this->failure(401, 'SESSION_REVOKED', $id);
        unset($store['sessions'][$token]);
        $this->saveStore($store);
        return [200, ['protocol' => $this->contract['protocol'], 'version' => $this->contract['version'], 'request_id' => $id, 'ok' => true, 'data' => ['ended_at' => gmdate('c')]]];
    }

    private function loadFixture(): array
    {
        if (!is_file($this->config['fixturePath'])) return ['operators' => []];
        $json = file_get_contents($this->config['fixturePath']);
        if ($json === false) return ['operators' => []];
        try { return json_decode($json, true, 8, JSON_THROW_ON_ERROR); } catch (\Throwable) { return ['operators' => []]; }
    }

    private function loadStore(): array
    {
        if (!is_file($this->config['storePath'])) return ['sessions' => []];
        $json = file_get_contents($this->config['storePath']);
        if ($json === false || $json === '') return ['sessions' => []];
        try { return json_decode($json, true, 8, JSON_THROW_ON_ERROR); } catch (\Throwable) { return ['sessions' => []]; }
    }

    private function saveStore(array $store): void
    {
        $dir = dirname($this->config['storePath']);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) throw new \RuntimeException('SESSION_STORE_WRITE_FAILED');
        file_put_contents($this->config['storePath'], json_encode($store, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    private function exactKeys(mixed $input, array $expected): bool
    {
        if (!is_array($input)) return false;
        $actual = array_keys($input);
        sort($actual);
        sort($expected);
        return $actual === $expected;
    }

    private function validToken(mixed $token): bool
    {
        return is_string($token) && preg_match('/\A[\x21-\x7e]{32,256}\z/D', $token) === 1;
    }

    private function validStringPath(mixed $path): bool
    {
        return is_string($path) && $path !== '';
    }

    private function failure(int $status, string $code, ?string $id): array
    {
        return [$status, ['protocol' => $this->contract['protocol'], 'version' => $this->contract['version'], 'request_id' => $id, 'ok' => false, 'error' => ['code' => $code, 'message' => $code]]];
    }
}
