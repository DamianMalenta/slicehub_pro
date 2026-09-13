<?php
declare(strict_types=1);
namespace SliceHub\Symbiont;

final class EngineeringRead
{
    private array $contract;
    private array $policy;

    public function __construct(private array $config)
    {
        $this->contract = json_decode(file_get_contents(__DIR__ . '/contracts/engineering-read-v1.json'), true, 32, JSON_THROW_ON_ERROR);
        $this->policy = json_decode(file_get_contents(__DIR__ . '/contracts/b001-package.json'), true, 32, JSON_THROW_ON_ERROR);
    }

    public static function environmentConfig(): array
    {
        return [
            'enabled' => getenv('SYMBIONT_ENGINEERING_ENABLED') === '1',
            'serviceToken' => (string)getenv('SYMBIONT_ENGINEERING_SERVICE_TOKEN'),
            'adminToken' => (string)getenv('SYMBIONT_ENGINEERING_ADMIN_TOKEN'),
            'bridgeServiceToken' => (string)getenv('SYMBIONT_BRIDGE_SERVICE_TOKEN'),
            'bridgeAdminToken' => (string)getenv('SYMBIONT_BRIDGE_ADMIN_TOKEN'),
            'packageFile' => (string)getenv('SYMBIONT_ENGINEERING_PACKAGE_FILE'),
            'hostId' => (string)getenv('SYMBIONT_BRIDGE_HOST_ID'),
            'environment' => (string)getenv('SYMBIONT_BRIDGE_ENVIRONMENT'),
        ];
    }

    private function keys(mixed $value, array $keys): bool
    {
        if (!is_array($value)) return false;
        $actual = array_keys($value);
        sort($actual);
        sort($keys);
        return $actual === $keys;
    }

    private function package(): array
    {
        $file = $this->config['packageFile'] ?? '';
        if (!$file || is_link($file) || !is_file($file) || filesize($file) > $this->contract['limits']['response_bytes']) throw new \RuntimeException('PACKAGE_UNAVAILABLE');
        $raw = file_get_contents($file, false, null, 0, $this->contract['limits']['response_bytes'] + 1);
        if ($raw === false || strlen($raw) > $this->contract['limits']['response_bytes']) throw new \RuntimeException('LIMIT_EXCEEDED');
        $bundle = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        if (!$this->keys($bundle, ['manifest', 'files'])) throw new \RuntimeException('INTEGRITY_ERROR');
        $m = $bundle['manifest'];
        $p = $this->policy;
        if (!$this->keys($m, $this->contract['manifest_fields']) || $m['package_id'] !== $p['package_id'] || $m['repo_id'] !== $p['repo_id'] || $m['commit'] !== $p['commit'] || $m['scope'] !== $this->contract['scope'] || !is_array($m['files']) || !is_array($bundle['files']) || count($m['files']) !== count($p['paths']) || count($bundle['files']) !== count($p['paths'])) throw new \RuntimeException('INTEGRITY_ERROR');
        $rows = [];
        $total = 0;
        foreach ($p['paths'] as $i => $path) {
            $entry = $m['files'][$i] ?? null;
            $source = $bundle['files'][$i] ?? null;
            if (!$this->keys($entry, $this->contract['file_fields']) || !$this->keys($source, ['path', 'content_base64']) || $entry['path'] !== $path || $source['path'] !== $path || !is_string($source['content_base64'])) throw new \RuntimeException('INTEGRITY_ERROR');
            $bytes = base64_decode($source['content_base64'], true);
            if ($bytes === false || base64_encode($bytes) !== $source['content_base64'] || strlen($bytes) > $this->contract['limits']['file_bytes']) throw new \RuntimeException('INTEGRITY_ERROR');
            $total += strlen($bytes);
            if ($entry['bytes'] !== strlen($bytes) || $entry['sha256'] !== hash('sha256', $bytes) || hash('sha1', 'blob ' . strlen($bytes) . "\0" . $bytes) !== $p['git_blobs'][$i]) throw new \RuntimeException('INTEGRITY_ERROR');
            $rows[] = [$path, $entry['bytes'], $entry['sha256']];
        }
        $digest = hash('sha256', json_encode([$p['package_id'], $p['repo_id'], $p['commit'], $rows], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        if ($total > $this->contract['limits']['package_bytes'] || $m['snapshot_id'] !== $digest || $m['manifest_sha256'] !== $digest) throw new \RuntimeException('INTEGRITY_ERROR');
        return $bundle;
    }

    public function handle(string $method, string $contentType, string $authorization, string $raw, string $audience, bool $secureTransport): array
    {
        $id = null;
        if (!$secureTransport) return $this->failure(403, 'UNAUTHORIZED', $id);
        $tokens = [$this->config['serviceToken'] ?? '', $this->config['adminToken'] ?? '', $this->config['bridgeServiceToken'] ?? '', $this->config['bridgeAdminToken'] ?? ''];
        if (empty($this->config['enabled']) || count(array_unique($tokens)) !== 4 || !preg_match('/\A[\x21-\x7e]{32,256}\z/D', $tokens[0]) || !preg_match('/\A[\x21-\x7e]{32,256}\z/D', $tokens[1]) || ($this->config['environment'] ?? '') !== 'test' || !preg_match('/\A[a-z0-9][a-z0-9-]{1,62}[a-z0-9]\z/D', $this->config['hostId'] ?? '')) return $this->failure(503, 'DISABLED', $id);
        $expected = match ($audience) { 'service' => $tokens[0], 'admin' => $tokens[1], default => '' };
        if (!$expected || !str_starts_with($authorization, 'Bearer ') || !hash_equals($expected, substr($authorization, 7))) return $this->failure(401, 'UNAUTHORIZED', $id);
        if ($method !== 'POST' || strtolower(trim(explode(';', $contentType)[0])) !== 'application/json' || strlen($raw) > $this->contract['limits']['request_bytes']) return $this->failure(400, 'INVALID_REQUEST', $id);
        try { $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); } catch (\Throwable) { return $this->failure(400, 'INVALID_REQUEST', $id); }
        if (!$this->keys($input, $this->contract['request_fields']) || !is_string($input['request_id']) || !preg_match('/\A[A-Za-z0-9_.-]{1,80}\z/D', $input['request_id'])) return $this->failure(400, 'INVALID_REQUEST', $id);
        $id = $input['request_id'];
        if ($input['protocol'] !== $this->contract['protocol'] || $input['version'] !== $this->contract['version']) return $this->failure(409, 'UNSUPPORTED_VERSION', $id);
        $operation = $input['operation'];
        if (!in_array($operation, $this->contract['operations'], true)) return $this->failure(400, 'INVALID_REQUEST', $id);
        if ($audience === 'admin' && $operation !== 'snapshot.describe') return $this->failure(403, 'UNAUTHORIZED', $id);
        $params = $input['params'];
        if (!$this->keys($params, $operation === 'snapshot.read' ? ['package_id', 'snapshot_id', 'manifest_sha256'] : ['package_id'])) return $this->failure(400, 'INVALID_REQUEST', $id);
        if ($params['package_id'] !== $this->policy['package_id']) return $this->failure(404, 'NOT_FOUND', $id);
        try {
            $bundle = $this->package();
            if ($operation === 'snapshot.read' && ($params['snapshot_id'] !== $bundle['manifest']['snapshot_id'] || $params['manifest_sha256'] !== $bundle['manifest']['manifest_sha256'])) return $this->failure(409, 'SNAPSHOT_CHANGED', $id);
            $data = ['identity' => ['host_id' => $this->config['hostId'], 'environment' => 'test', 'adapter_version' => $this->contract['version']], 'observed_at' => gmdate('c'), 'manifest' => $bundle['manifest']];
            if ($operation === 'snapshot.read') $data['files'] = $bundle['files'];
            return [200, ['protocol' => $this->contract['protocol'], 'version' => $this->contract['version'], 'request_id' => $id, 'ok' => true, 'data' => $data]];
        } catch (\Throwable $error) {
            $code = in_array($error->getMessage(), ['PACKAGE_UNAVAILABLE', 'INTEGRITY_ERROR', 'LIMIT_EXCEEDED'], true) ? $error->getMessage() : 'PACKAGE_UNAVAILABLE';
            return $this->failure(503, $code, $id);
        }
    }

    private function failure(int $status, string $code, ?string $id): array
    {
        return [$status, ['protocol' => $this->contract['protocol'], 'version' => $this->contract['version'], 'request_id' => $id, 'ok' => false, 'error' => ['code' => $code, 'message' => $code]]];
    }
}
