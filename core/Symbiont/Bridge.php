<?php
declare(strict_types=1);
namespace SliceHub\Symbiont;

final class Bridge
{
    private array $contract;

    public function __construct(private array $config)
    {
        $this->contract = json_decode(file_get_contents(__DIR__ . '/contracts/protocol-v1.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    public static function environmentConfig(): array
    {
        return [
            'enabled' => getenv('SYMBIONT_BRIDGE_ENABLED') === '1',
            'serviceToken' => (string)(getenv('SYMBIONT_BRIDGE_SERVICE_TOKEN') ?: ''),
            'adminToken' => (string)(getenv('SYMBIONT_BRIDGE_ADMIN_TOKEN') ?: ''),
            'hostId' => (string)(getenv('SYMBIONT_BRIDGE_HOST_ID') ?: 'slicehub-host'),
            'environment' => (string)(getenv('SYMBIONT_BRIDGE_ENVIRONMENT') ?: 'development'),
        ];
    }

    public function handle(string $method, string $contentType, string $authorization, string $raw, string $audience, bool $secureTransport): array
    {
        $id = null;
        if (!$secureTransport) return $this->failure(403, 'UNAUTHORIZED', 'TLS required outside loopback.', $id);
        $service = $this->config['serviceToken'] ?? '';
        $admin = $this->config['adminToken'] ?? '';
        if (empty($this->config['enabled']) || strlen($service) < 32 || strlen($admin) < 32 || hash_equals($service, $admin)) return $this->failure(503, 'DISABLED', 'Bridge disabled or not configured.', $id);
        $expected = match ($audience) { 'service' => $service, 'admin' => $admin, default => '' };
        if ($expected === '' || !str_starts_with($authorization, 'Bearer ') || !hash_equals($expected, substr($authorization, 7))) return $this->failure(401, 'UNAUTHORIZED', 'Access denied.', $id);
        if ($method !== 'POST') return $this->failure(405, 'INVALID_REQUEST', 'Use POST.', $id);
        if (strtolower(trim(explode(';', $contentType)[0])) !== 'application/json') return $this->failure(415, 'INVALID_REQUEST', 'Use application/json.', $id);
        if (strlen($raw) > $this->contract['limits']['request_bytes']) return $this->failure(413, 'INVALID_REQUEST', 'Request too large.', $id);
        try {
            $input = json_decode($raw, false, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->failure(400, 'INVALID_REQUEST', 'Invalid JSON.', $id);
        }
        if (!$input instanceof \stdClass) return $this->failure(400, 'INVALID_REQUEST', 'Object required.', $id);
        $fields = get_object_vars($input);
        $keys = array_keys($fields);
        sort($keys);
        $required = $this->contract['request']['required'];
        sort($required);
        if ($keys !== $required || !is_string($input->request_id) || !preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/D', $input->request_id)) return $this->failure(400, 'INVALID_REQUEST', 'Invalid request fields.', $id);
        $id = $input->request_id;
        if ($input->protocol !== $this->contract['protocol'] || $input->version !== $this->contract['version']) return $this->failure(409, 'UNSUPPORTED_VERSION', 'Unsupported protocol version.', $id);
        if (!is_string($input->operation) || !in_array($input->operation, $this->contract['operations'], true)) return $this->failure(422, 'UNSUPPORTED_OPERATION', 'Operation not supported.', $id);
        $hostId = $this->config['hostId'] ?? '';
        $environment = $this->config['environment'] ?? '';
        if (!is_string($hostId) || !preg_match('/\A[a-z0-9][a-z0-9-]{1,62}[a-z0-9]\z/D', $hostId) || !in_array($environment, $this->contract['identity']['environments'], true)) return $this->failure(503, 'DISABLED', 'Bridge identity is not configured.', $id);
        $identity = ['host_id' => $hostId, 'display_name' => 'SliceHub', 'environment' => $environment, 'adapter_version' => '1.0.0', 'capabilities' => $this->contract['identity']['capabilities']];
        $data = $input->operation === 'describe' ? $identity : ['identity' => $identity, 'observed_at' => gmdate('c'), 'checks' => ['bridge' => 'ok', 'business_access' => 'not_requested']];
        return [200, ['protocol' => $this->contract['protocol'], 'version' => $this->contract['version'], 'request_id' => $id, 'ok' => true, 'data' => $data]];
    }

    private function failure(int $status, string $code, string $message, ?string $id): array
    {
        return [$status, ['protocol' => $this->contract['protocol'], 'version' => $this->contract['version'], 'request_id' => $id, 'ok' => false, 'error' => ['code' => $code, 'message' => $message]]];
    }
}
