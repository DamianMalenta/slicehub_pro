<?php
declare(strict_types=1);
namespace SliceHub\Symbiont;

final class CapabilityRegistry
{
    private array $contract;
    private array $catalog;

    public function __construct(private array $config)
    {
        $this->contract = json_decode(file_get_contents(__DIR__ . '/contracts/capability-catalog-v1.json'), true, 32, JSON_THROW_ON_ERROR);
        $this->catalog = json_decode(file_get_contents(__DIR__ . '/contracts/capability-catalog.json'), true, 32, JSON_THROW_ON_ERROR);
        $this->validateCatalog();
    }

    public static function environmentConfig(): array
    {
        return [
            'enabled' => getenv('SYMBIONT_CAPABILITY_CATALOG_ENABLED') === '1',
            'serviceToken' => (string)getenv('SYMBIONT_CAPABILITY_CATALOG_SERVICE_TOKEN'),
            'adminToken' => (string)getenv('SYMBIONT_CAPABILITY_CATALOG_ADMIN_TOKEN'),
            'hostId' => (string)getenv('SYMBIONT_BRIDGE_HOST_ID'),
            'environment' => (string)getenv('SYMBIONT_BRIDGE_ENVIRONMENT'),
        ];
    }

    public function handle(string $method, string $contentType, string $authorization, string $raw, string $audience, bool $secureTransport): array
    {
        $id = null;
        if (!$secureTransport) return $this->failure(403, 'UNAUTHORIZED', $id);
        $service = $this->config['serviceToken'] ?? '';
        $admin = $this->config['adminToken'] ?? '';
        if (empty($this->config['enabled']) || !$this->validToken($service) || !$this->validToken($admin) || hash_equals($service, $admin) || !preg_match('/\A[a-z0-9][a-z0-9-]{1,62}[a-z0-9]\z/D', $this->config['hostId'] ?? '') || !in_array($this->config['environment'] ?? '', ['development', 'test', 'staging', 'production'], true)) return $this->failure(503, 'DISABLED', $id);
        $expected = match ($audience) { 'service' => $service, 'admin' => $admin, default => '' };
        if (!$expected || !str_starts_with($authorization, 'Bearer ') || !hash_equals($expected, substr($authorization, 7))) return $this->failure(401, 'UNAUTHORIZED', $id);
        if ($method !== 'POST' || strtolower(trim(explode(';', $contentType)[0])) !== 'application/json' || strlen($raw) > $this->contract['limits']['request_bytes']) return $this->failure(400, 'INVALID_REQUEST', $id);
        try { $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); } catch (\Throwable) { return $this->failure(400, 'INVALID_REQUEST', $id); }
        if (!$this->exactKeys($input, $this->contract['request_fields']) || !is_string($input['request_id']) || !preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/D', $input['request_id'])) return $this->failure(400, 'INVALID_REQUEST', $id);
        $id = $input['request_id'];
        if ($input['protocol'] !== $this->contract['protocol'] || $input['version'] !== $this->contract['version']) return $this->failure(409, 'UNSUPPORTED_VERSION', $id);
        if ($input['operation'] !== 'catalog.list') return $this->failure(422, 'UNSUPPORTED_OPERATION', $id);
        if ($this->catalog['capabilities'] === []) return $this->failure(503, 'EMPTY_CATALOG', $id);
        $data = ['identity' => ['host_id' => $this->config['hostId'], 'environment' => $this->config['environment'], 'adapter_version' => $this->contract['version']], 'observed_at' => gmdate('c'), 'catalog_id' => $this->catalog['catalog_id'], 'capabilities' => $this->catalog['capabilities'], 'execution' => 'not_permitted'];
        return [200, ['protocol' => $this->contract['protocol'], 'version' => $this->contract['version'], 'request_id' => $id, 'ok' => true, 'data' => $data]];
    }

    private function validateCatalog(): void
    {
        if (!$this->exactKeys($this->catalog, ['catalog_id', 'capabilities']) || !is_string($this->catalog['catalog_id']) || !preg_match('/\A[a-z0-9][a-z0-9.-]{1,79}\z/D', $this->catalog['catalog_id']) || !is_array($this->catalog['capabilities']) || count($this->catalog['capabilities']) > $this->contract['limits']['capabilities']) throw new \RuntimeException('INVALID_CATALOG');
        $ids = [];
        foreach ($this->catalog['capabilities'] as $capability) {
            if (!$this->exactKeys($capability, $this->contract['capability_fields']) || !preg_match('/\A[a-z][a-z0-9.-]{2,79}\z/D', $capability['id'] ?? '') || !preg_match('/\A\d+\.\d+\.\d+\z/D', $capability['version'] ?? '') || !in_array($capability['kind'] ?? '', $this->contract['kinds'], true) || !in_array($capability['status'] ?? '', $this->contract['statuses'], true) || ($capability['business_effects'] ?? '') !== 'none') throw new \RuntimeException('INVALID_CATALOG');
            if (isset($ids[$capability['id']])) throw new \RuntimeException('INVALID_CATALOG');
            $ids[$capability['id']] = true;
            foreach (['summary', 'required_actor_role', 'input_schema', 'output_schema'] as $field) if (!is_string($capability[$field]) || $capability[$field] === '' || strlen($capability[$field]) > 240) throw new \RuntimeException('INVALID_CATALOG');
        }
    }

    private function exactKeys(mixed $value, array $expected): bool
    {
        if (!is_array($value)) return false;
        $actual = array_keys($value);
        sort($actual);
        sort($expected);
        return $actual === $expected;
    }

    private function validToken(mixed $token): bool
    {
        return is_string($token) && preg_match('/\A[\x21-\x7e]{32,256}\z/D', $token) === 1;
    }

    private function failure(int $status, string $code, ?string $id): array
    {
        return [$status, ['protocol' => $this->contract['protocol'], 'version' => $this->contract['version'], 'request_id' => $id, 'ok' => false, 'error' => ['code' => $code, 'message' => $code]]];
    }
}
