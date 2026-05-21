<?php

declare(strict_types=1);

namespace SliceHub\Ksef;

require_once __DIR__ . '/../CredentialVault.php';

/**
 * SliceHub — KSeF API Client (oficjalne API v2 MF)
 *
 * Hosty (OpenAPI v2):
 *   Test: https://api-test.ksef.mf.gov.pl/v2
 *   Prod: https://api.ksef.mf.gov.pl/v2
 *
 * Uwierzytelnianie (KSeF Token z podatki.gov.pl):
 *   challenge → RSA-OAEP(SHA-256) → /auth/ksef-token (kontekst NIP) → poll
 *   /auth/{ref} → /auth/token/redeem → JWT access + refresh.
 *   Kolejne żądania: Authorization: Bearer (access); odświeżanie: /auth/token/refresh.
 *
 * Szyfrowanie RSA wymaga binarki OpenSSL 3.x (`openssl pkeyutl`, OAEP SHA-256) —
 * typowy hosting LAMP ma `/usr/bin/openssl`.
 *
 * Mock mode: `environment === 'mock'` — fixtures jak wcześniej (F4).
 *
 * Konstytucja v5: token NIGDY w logach; CredentialVault dla credentials w DB;
 * każde SQL z barierą tenant_id.
 *
 * Sesja F4 · 2026-05-11 · migracja na API v2 · 2026-05-14.
 */
class Client
{
    public const ENV_SANDBOX = 'sandbox';
    public const ENV_PROD    = 'prod';
    public const ENV_MOCK    = 'mock';

    private const URLS = [
        'sandbox' => 'https://api-test.ksef.mf.gov.pl/v2',
        'prod'    => 'https://api.ksef.mf.gov.pl/v2',
        'mock'    => 'mock://internal',
    ];

    /** Klucze JSON w credentials (obok environment, token). */
    private const CRED_REFRESH = 'ksef_refresh_token';
    private const CRED_ACCESS  = 'ksef_access_token';
    private const CRED_ACCESS_UNTIL = 'ksef_access_valid_until';

    private \PDO $pdo;
    private int $tenantId;
    private string $environment = self::ENV_MOCK;
    /** Token KSeF z portalu MF (pole `token` w credentials). */
    private ?string $token = null;
    private string $baseUrl = '';
    /** Timeout cURL (s) — pierwsze pobranie metadanych + wiele stron może trwać dłużej niż 30 s. */
    private int $timeout = 90;

    /** MF: maks. okres zapytania metadata ~3 mies.; bezpiecznie 90 dni wstecz od „teraz”. */
    private const METADATA_MAX_RANGE_DAYS = 90;
    /** Rozmiar strony /invoices/query/metadata (OpenAPI MF: min 10, max 250). */
    private const METADATA_PAGE_SIZE = 250;
    /** Zabezpieczenie przed nieskończoną pętlą (np. 100 × 250 wierszy na przebieg metadata). */
    private const METADATA_MAX_PAGES = 100;

    private ?string $tenantNip = null;

    private ?string $ksefRefreshToken = null;
    private ?string $ksefAccessToken = null;
    private int $ksefAccessValidUntil = 0;

    /** Rekord `sh_tenant_integrations` (provider=ksef): is_active=0 blokuje wywołania API (sandbox/prod). */
    private bool $ksefIntegrationActive = true;

    public function __construct(\PDO $pdo, int $tenantId)
    {
        $this->pdo = $pdo;
        $this->tenantId = $tenantId;
        $this->loadConfig();
    }

    /**
     * Wczytaj config z sh_tenant_integrations (provider='ksef') + NIP z sh_tenant
     * (ten sam co w Backoffice → Profil firmy / pole „NIP”).
     */
    private function loadConfig(): void
    {
        $stNip = $this->pdo->prepare('SELECT nip FROM sh_tenant WHERE id = :tid LIMIT 1');
        $stNip->execute([':tid' => $this->tenantId]);
        $nip = $stNip->fetchColumn();
        $this->tenantNip = is_string($nip) && $nip !== '' ? self::normalizeNip($nip) : null;

        $st = $this->pdo->prepare(
            "SELECT api_base_url, credentials, is_active
               FROM sh_tenant_integrations
              WHERE tenant_id = :tid AND provider = 'ksef'
              LIMIT 1"
        );
        $st->execute([':tid' => $this->tenantId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            $this->environment = self::ENV_MOCK;
            $this->baseUrl = self::URLS['mock'];
            return;
        }

        $this->ksefIntegrationActive = ((int) ($row['is_active'] ?? 1)) === 1;

        $credsRaw = (string) ($row['credentials'] ?? '');
        if ($credsRaw !== '') {
            $plain = \CredentialVault::decrypt($credsRaw);
            if ($plain === null) {
                $plain = $credsRaw;
            }
            $creds = json_decode($plain, true);
            if (is_array($creds)) {
                $this->environment = (string) ($creds['environment'] ?? self::ENV_MOCK);
                $this->token = (string) ($creds['token'] ?? '');
                if ($this->token === '') {
                    $this->token = null;
                }
                $this->ksefRefreshToken = isset($creds[self::CRED_REFRESH]) && is_string($creds[self::CRED_REFRESH])
                    ? $creds[self::CRED_REFRESH]
                    : null;
                if ($this->ksefRefreshToken === '') {
                    $this->ksefRefreshToken = null;
                }
                $this->ksefAccessToken = isset($creds[self::CRED_ACCESS]) && is_string($creds[self::CRED_ACCESS])
                    ? $creds[self::CRED_ACCESS]
                    : null;
                if ($this->ksefAccessToken === '') {
                    $this->ksefAccessToken = null;
                }
                $vu = $creds[self::CRED_ACCESS_UNTIL] ?? null;
                if (is_string($vu) && $vu !== '') {
                    $ts = strtotime($vu);
                    $this->ksefAccessValidUntil = $ts !== false ? $ts : 0;
                } else {
                    $this->ksefAccessValidUntil = 0;
                }
            }
        }

        $this->baseUrl = (string) ($row['api_base_url'] ?? '');
        if ($this->baseUrl === '') {
            $this->baseUrl = self::URLS[$this->environment] ?? self::URLS['mock'];
        }
    }

    public function getEnvironment(): string
    {
        return $this->environment;
    }

    public function isMockMode(): bool
    {
        return $this->environment === self::ENV_MOCK;
    }

    public function hasToken(): bool
    {
        return $this->token !== null && $this->token !== '';
    }

    /** @return null|string komunikat gdy integracja wyłączona w DB (nie dotyczy mock). */
    private function integrationInactiveMessage(): ?string
    {
        if ($this->isMockMode() || $this->ksefIntegrationActive) {
            return null;
        }

        return 'Integracja KSeF jest wyłączona (is_active=0). Włącz powiązanie w bazie lub ponownie zapisz konfigurację w Inbox KSeF.';
    }

    /**
     * @return array{success:bool, environment:string, message:string, http_code?:int}
     */
    public function testConnection(): array
    {
        if ($this->isMockMode()) {
            return [
                'success'     => true,
                'environment' => 'mock',
                'message'     => 'Mock mode — żadne realne wywołanie. Zmień environment na sandbox/prod po dodaniu KSeF Token.',
            ];
        }
        $inactive = $this->integrationInactiveMessage();
        if ($inactive !== null) {
            return [
                'success'     => false,
                'environment' => $this->environment,
                'message'     => $inactive,
            ];
        }
        if (!$this->hasToken()) {
            return [
                'success'     => false,
                'environment' => $this->environment,
                'message'     => 'Brak KSeF Token. Skonfiguruj w module Inbox KSeF.',
            ];
        }
        if ($this->tenantNip === null || strlen($this->tenantNip) !== 10) {
            return [
                'success'     => false,
                'environment' => $this->environment,
                'message'     => 'Brak poprawnego NIP (10 cyfr). Uzupełnij go w Backoffice → Profil firmy — wymagany do uwierzytelnienia KSeF API v2.',
            ];
        }

        $err = $this->ensureAccessToken();
        if ($err !== null) {
            return [
                'success'     => false,
                'environment' => $this->environment,
                'message'     => $err,
            ];
        }

        $probe = $this->requestWithAccessToken('GET', '/rate-limits', null, []);
        if ($probe['code'] >= 200 && $probe['code'] < 300) {
            return [
                'success'     => true,
                'environment' => $this->environment,
                'message'     => "Połączenie z KSeF API v2 ({$this->environment}) OK — uwierzytelnianie i limiter odpowiedziały poprawnie.",
                'http_code'   => $probe['code'],
            ];
        }

        return [
            'success'     => false,
            'environment' => $this->environment,
            'message'     => $this->formatHttpFailure('GET /rate-limits', $probe),
            'http_code'   => $probe['code'],
        ];
    }

    /**
     * Lista faktur (metadata). ref_id = numer KSeF (ksefNumber) — używany przez worker i deduplikację.
     *
     * Dwa przebiegi `Issue` oraz `Invoicing` (scalone po ksefNumber): portal MF i filtry dat bywają niespójne;
     * samo Invoicing pomijało realnie nowe pozycje widoczne użytkownikowi. Koszt: więcej POST-ów metadata (limity MF).
     *
     * @param string|null $sinceDate Y-m-d — dolna granica (UTC północ); null = maks. okres wstecz (90 dni, limit MF)
     * @param string|null $lastSeenId legacy (API 1.0) — w v2 ignorowany; worker zapisuje go dla audytu, zakres dat z `last_polled_at`
     * @return array{success:bool, invoices: list<array{ref_id:string, supplier_nip:string, invoice_number:string, issue_date:string}>, message?:string}
     */
    public function queryInbox(?string $sinceDate = null, ?string $lastSeenId = null): array
    {
        unset($lastSeenId);

        if ($this->isMockMode()) {
            return $this->mockQueryInbox($sinceDate);
        }
        $inactive = $this->integrationInactiveMessage();
        if ($inactive !== null) {
            return ['success' => false, 'invoices' => [], 'message' => $inactive];
        }
        if (!$this->hasToken()) {
            return ['success' => false, 'invoices' => [], 'message' => 'Brak KSeF Token.'];
        }
        if ($this->tenantNip === null || strlen($this->tenantNip) !== 10) {
            return ['success' => false, 'invoices' => [], 'message' => 'Brak NIP. Uzupełnij w Backoffice → Profil firmy.'];
        }

        $err = $this->ensureAccessToken();
        if ($err !== null) {
            return ['success' => false, 'invoices' => [], 'message' => $err];
        }

        $fromIso = $this->buildMetadataDateFrom($sinceDate);
        $toIso = gmdate('Y-m-d\TH:i:s\Z');

        // Subject2: nabywca z JWT (bez buyerIdentifier w body — 21405).
        [$err1, $issueRows] = $this->collectMetadataPages('Issue', $fromIso, $toIso);
        if ($err1 !== null) {
            return ['success' => false, 'invoices' => [], 'message' => $err1];
        }
        [$err2, $invRows] = $this->collectMetadataPages('Invoicing', $fromIso, $toIso);
        if ($err2 !== null) {
            return ['success' => false, 'invoices' => [], 'message' => $err2];
        }

        $merged = [];
        foreach (array_merge($issueRows, $invRows) as $row) {
            $k = $row['ref_id'];
            if ($k !== '') {
                $merged[$k] = $row;
            }
        }

        return ['success' => true, 'invoices' => array_values($merged)];
    }

    /**
     * Pobiera metadane faktur (Subject2) dla jednego dateType, ze stronicowaniem.
     * UWAGA: pageOffset w API MF to numer strony (0,1,2…), NIE offset wiersza.
     *
     * @return array{0: ?string, 1: list<array{ref_id:string, supplier_nip:string, invoice_number:string, issue_date:string}>}
     */
    private function collectMetadataPages(string $dateType, string $fromIso, string $toIso): array
    {
        $body = [
            'subjectType' => 'Subject2',
            'dateRange'   => [
                'dateType' => $dateType,
                'from'     => $fromIso,
                'to'       => $toIso,
            ],
        ];

        $rows = [];
        $pageIndex = 0;
        for ($p = 0; $p < self::METADATA_MAX_PAGES; $p++) {
            $query = [
                'sortOrder'  => 'Desc',
                'pageOffset' => $pageIndex,
                'pageSize'   => self::METADATA_PAGE_SIZE,
            ];

            $res = $this->requestWithAccessToken('POST', '/invoices/query/metadata', $body, $query);
            if ($res['code'] !== 200) {
                return [
                    $this->formatHttpFailure('POST /invoices/query/metadata (' . $dateType . ')', $res),
                    [],
                ];
            }
            $json = $res['json'];
            if (!is_array($json)) {
                return ['Invalid JSON response (metadata ' . $dateType . ')', []];
            }

            foreach (($json['invoices'] ?? []) as $inv) {
                if (!is_array($inv)) {
                    continue;
                }
                $ksefNo = (string) ($inv['ksefNumber'] ?? '');
                if ($ksefNo === '') {
                    continue;
                }
                $seller = $inv['seller'] ?? [];
                $sellerNip = is_array($seller) ? (string) ($seller['nip'] ?? '') : '';
                $nipNorm = self::normalizeNip($sellerNip);
                $rows[] = [
                    'ref_id'         => $ksefNo,
                    'supplier_nip'   => $nipNorm ?? $sellerNip,
                    'invoice_number' => (string) ($inv['invoiceNumber'] ?? ''),
                    'issue_date'     => (string) ($inv['issueDate'] ?? ''),
                ];
            }

            $hasMore = isset($json['hasMore']) && $json['hasMore'] === true;
            if (!$hasMore) {
                break;
            }
            $pageIndex++;
        }

        return [null, $rows];
    }

    /**
     * Pobierz XML faktury po numerze KSeF (dawniej ref. z API 1.0 — teraz wyłącznie ksefNumber).
     *
     * @return array{success:bool, xml?:string, message?:string}
     */
    public function fetchInvoiceXml(string $refId): array
    {
        if ($this->isMockMode()) {
            return $this->mockFetchInvoiceXml($refId);
        }
        $inactive = $this->integrationInactiveMessage();
        if ($inactive !== null) {
            return ['success' => false, 'message' => $inactive];
        }
        if (!$this->hasToken()) {
            return ['success' => false, 'message' => 'Brak KSeF Token.'];
        }
        if ($this->tenantNip === null || strlen($this->tenantNip) !== 10) {
            return ['success' => false, 'message' => 'Brak NIP. Uzupełnij w Backoffice → Profil firmy.'];
        }

        $err = $this->ensureAccessToken();
        if ($err !== null) {
            return ['success' => false, 'message' => $err];
        }

        $path = '/invoices/ksef/' . rawurlencode($refId);
        $res = $this->requestWithAccessToken('GET', $path, null, []);
        if ($res['code'] !== 200) {
            return ['success' => false, 'message' => $this->formatHttpFailure('GET invoice XML', $res)];
        }
        return ['success' => true, 'xml' => (string) $res['body']];
    }

    // -------------------------------------------------------------------------
    // Uwierzytelnianie API v2
    // -------------------------------------------------------------------------

    /** @return null|string null = OK, string = komunikat błędu */
    private function ensureAccessToken(): ?string
    {
        if ($this->ksefAccessToken && $this->ksefAccessValidUntil > time() + 30) {
            return null;
        }
        if ($this->ksefRefreshToken) {
            if ($this->tryRefreshAccessToken()) {
                return null;
            }
            $this->ksefRefreshToken = null;
            $this->persistCredentialPatch([self::CRED_REFRESH => null]);
        }
        return $this->authenticateFullKsefTokenFlow();
    }

    private function tryRefreshAccessToken(): bool
    {
        $res = $this->httpRequest('POST', '/auth/token/refresh', null, [], $this->ksefRefreshToken);
        if ($res['code'] !== 200 || !is_array($res['json'])) {
            return false;
        }
        $acc = $res['json']['accessToken'] ?? null;
        if (!is_array($acc)) {
            return false;
        }
        $tok = (string) ($acc['token'] ?? '');
        $vu = (string) ($acc['validUntil'] ?? '');
        if ($tok === '' || $vu === '') {
            return false;
        }
        $exp = strtotime($vu);
        $this->ksefAccessToken = $tok;
        $this->ksefAccessValidUntil = $exp !== false ? $exp : (time() + 300);
        $this->persistCredentialPatch([
            self::CRED_ACCESS        => $this->ksefAccessToken,
            self::CRED_ACCESS_UNTIL  => $vu,
        ]);
        return true;
    }

    /** @return null|string */
    private function authenticateFullKsefTokenFlow(): ?string
    {
        $portal = $this->token;
        if ($portal === null || $portal === '') {
            return 'Brak KSeF Token.';
        }
        $nip = $this->tenantNip;
        if ($nip === null || strlen($nip) !== 10) {
            return 'Brak poprawnego NIP (10 cyfr). Uzupełnij w Backoffice → Profil firmy.';
        }

        $certs = $this->httpRequest('GET', '/security/public-key-certificates', null, [], null);
        if ($certs['code'] !== 200 || !is_array($certs['json'])) {
            return 'Nie udało się pobrać certyfikatów KSeF (/security/public-key-certificates).';
        }
        $selected = $this->selectTokenEncryptionCertificate($certs['json']);
        if ($selected === null) {
            return 'Brak certyfikatu KSeF do szyfrowania tokenu (usage=KsefTokenEncryption).';
        }

        $ch = $this->httpRequest('POST', '/auth/challenge', [], [], null);
        if ($ch['code'] !== 200 || !is_array($ch['json'])) {
            return 'Nie udało się uzyskać challenge (/auth/challenge).';
        }
        $challenge = (string) ($ch['json']['challenge'] ?? '');
        $tsMs = $ch['json']['timestampMs'] ?? null;
        if ($challenge === '' || !is_int($tsMs)) {
            return 'Niepoprawna odpowiedź /auth/challenge.';
        }

        try {
            $cipherB64 = $this->rsaOaepSha256EncryptBase64(
                (string) $selected['certificate'],
                $portal . '|' . (string) $tsMs
            );
        } catch (\Throwable $e) {
            return 'Błąd szyfrowania tokenu KSeF: ' . $e->getMessage();
        }

        $publicKeyId = (string) ($selected['publicKeyId'] ?? '');
        $body = [
            'challenge'         => $challenge,
            'contextIdentifier' => [
                'type'  => 'Nip',
                'value' => $nip,
            ],
            'encryptedToken' => $cipherB64,
            'publicKeyId'    => $publicKeyId !== '' ? $publicKeyId : null,
        ];

        $init = $this->httpRequest('POST', '/auth/ksef-token', $body, [], null);
        if ($init['code'] !== 202 || !is_array($init['json'])) {
            return 'Odrzucono /auth/ksef-token: ' . $this->formatHttpFailure('POST /auth/ksef-token', $init);
        }
        $ref = (string) ($init['json']['referenceNumber'] ?? '');
        $authTok = $init['json']['authenticationToken'] ?? null;
        $bearerInit = is_array($authTok) ? (string) ($authTok['token'] ?? '') : '';
        if ($ref === '' || $bearerInit === '') {
            return 'Niepoprawna odpowiedź inicjacji uwierzytelniania.';
        }

        $deadline = time() + 45;
        $statusJson = null;
        while (time() < $deadline) {
            $st = $this->httpRequest('GET', '/auth/' . rawurlencode($ref), null, [], $bearerInit);
            $statusJson = is_array($st['json']) ? $st['json'] : null;
            $code = is_array($statusJson['status'] ?? null)
                ? (int) ($statusJson['status']['code'] ?? 0)
                : 0;
            if ($code === 200) {
                break;
            }
            if ($code >= 400 && $code !== 100) {
                $desc = is_array($statusJson['status'] ?? null)
                    ? (string) ($statusJson['status']['description'] ?? 'błąd')
                    : 'błąd';
                return "Uwierzytelnianie KSeF nieudane (status {$code}): {$desc}";
            }
            usleep(300_000);
        }
        if (!is_array($statusJson) || (int) ($statusJson['status']['code'] ?? 0) !== 200) {
            return 'Timeout oczekiwania na zakończenie uwierzytelniania KSeF.';
        }

        $redeem = $this->httpRequest('POST', '/auth/token/redeem', [], [], $bearerInit);
        if ($redeem['code'] !== 200 || !is_array($redeem['json'])) {
            return 'Wymiana tokenu nieudana (/auth/token/redeem): ' . $this->formatHttpFailure('POST /auth/token/redeem', $redeem);
        }
        $access = $redeem['json']['accessToken'] ?? null;
        $refresh = $redeem['json']['refreshToken'] ?? null;
        if (!is_array($access) || !is_array($refresh)) {
            return 'Niepoprawna odpowiedź /auth/token/redeem.';
        }
        $at = (string) ($access['token'] ?? '');
        $atUntil = (string) ($access['validUntil'] ?? '');
        $rt = (string) ($refresh['token'] ?? '');
        if ($at === '' || $rt === '' || $atUntil === '') {
            return 'Niekompletne tokeny po /auth/token/redeem.';
        }
        $exp = strtotime($atUntil);
        $this->ksefAccessToken = $at;
        $this->ksefAccessValidUntil = $exp !== false ? $exp : (time() + 300);
        $this->ksefRefreshToken = $rt;

        $this->persistCredentialPatch([
            self::CRED_REFRESH       => $this->ksefRefreshToken,
            self::CRED_ACCESS       => $this->ksefAccessToken,
            self::CRED_ACCESS_UNTIL => $atUntil,
        ]);

        return null;
    }

    /**
     * @param list<array<string,mixed>>|array<string,mixed> $decodedJson
     * @return array{certificate:string,publicKeyId:string}|null
     */
    private function selectTokenEncryptionCertificate($decodedJson): ?array
    {
        $list = isset($decodedJson[0]) ? $decodedJson : [];
        if ($list === [] && isset($decodedJson['certificate'])) {
            $list = [$decodedJson];
        }
        $now = time();
        foreach ($list as $row) {
            if (!is_array($row)) {
                continue;
            }
            $usage = $row['usage'] ?? [];
            if (!is_array($usage) || !in_array('KsefTokenEncryption', $usage, true)) {
                continue;
            }
            $cert = (string) ($row['certificate'] ?? '');
            if ($cert === '') {
                continue;
            }
            $vf = strtotime((string) ($row['validFrom'] ?? ''));
            $vt = strtotime((string) ($row['validTo'] ?? ''));
            if ($vf !== false && $now < $vf) {
                continue;
            }
            if ($vt !== false && $now > $vt) {
                continue;
            }
            return [
                'certificate' => $cert,
                'publicKeyId' => (string) ($row['publicKeyId'] ?? ''),
            ];
        }
        return null;
    }

    private function rsaOaepSha256EncryptBase64(string $certificateDerBase64, string $plaintext): string
    {
        $der = base64_decode($certificateDerBase64, true);
        if ($der === false || $der === '') {
            throw new \RuntimeException('Niepoprawny certyfikat (Base64).');
        }
        $b64 = base64_encode($der);
        $pem = "-----BEGIN CERTIFICATE-----\n" . chunk_split($b64, 64, "\n") . '-----END CERTIFICATE-----';

        $openssl = self::resolveOpensslBinary();
        $tmpPem = tempnam(sys_get_temp_dir(), 'ksefpem');
        $tmpIn = tempnam(sys_get_temp_dir(), 'ksefin');
        $tmpOut = tempnam(sys_get_temp_dir(), 'ksefout');
        if ($tmpPem === false || $tmpIn === false || $tmpOut === false) {
            throw new \RuntimeException('Brak katalogu tymczasowego.');
        }
        try {
            file_put_contents($tmpPem, $pem);
            file_put_contents($tmpIn, $plaintext);
            $cmd = [
                $openssl, 'pkeyutl', '-encrypt',
                '-inkey', $tmpPem,
                '-certin',
                '-in', $tmpIn,
                '-out', $tmpOut,
                '-pkeyopt', 'rsa_padding_mode:oaep',
                '-pkeyopt', 'rsa_oaep_md:sha256',
                '-pkeyopt', 'rsa_mgf1_md:sha256',
            ];
            $descSpec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $proc = proc_open($cmd, $descSpec, $pipes, null, null);
            if (!is_resource($proc)) {
                throw new \RuntimeException('proc_open(openssl) niedostępne.');
            }
            fclose($pipes[0]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $code = proc_close($proc);
            if ($code !== 0) {
                throw new \RuntimeException(trim($stderr ?: 'openssl pkeyutl failed'));
            }
            $bin = file_get_contents($tmpOut);
            if ($bin === false || $bin === '') {
                throw new \RuntimeException('Pusty wynik szyfrowania RSA.');
            }
            return base64_encode($bin);
        } finally {
            @unlink($tmpPem);
            @unlink($tmpIn);
            @unlink($tmpOut);
        }
    }

    private static function resolveOpensslBinary(): string
    {
        foreach (['/usr/bin/openssl', '/bin/openssl'] as $p) {
            if (is_file($p) && is_executable($p)) {
                return $p;
            }
        }
        return 'openssl';
    }

    /**
     * @param array<string,mixed>|null $jsonBody
     * @param array<string,string|int> $query
     * @return array{code:int, body:string, json:mixed}
     */
    private function requestWithAccessToken(string $method, string $path, ?array $jsonBody, array $query): array
    {
        for ($i = 0; $i < 2; $i++) {
            $err = $this->ensureAccessToken();
            if ($err !== null) {
                return ['code' => 0, 'body' => $err, 'json' => null];
            }
            $res = $this->httpRequest($method, $path, $jsonBody, $query, $this->ksefAccessToken);
            if ($res['code'] !== 401) {
                return $res;
            }
            $this->ksefAccessToken = null;
            $this->ksefAccessValidUntil = 0;
            $this->persistCredentialPatch([
                self::CRED_ACCESS       => null,
                self::CRED_ACCESS_UNTIL => null,
            ]);
        }
        return $res ?? ['code' => 401, 'body' => 'Unauthorized', 'json' => null];
    }

    /**
     * @param array<string,mixed>|null $jsonBody
     * @param array<string,string|int> $query
     * @return array{code:int, body:string, json:mixed}
     */
    private function httpRequest(string $method, string $path, ?array $jsonBody, array $query, ?string $bearer): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
        $m = strtoupper($method);
        $headers = [
            'Accept: application/json, application/problem+json, application/xml',
            'User-Agent: SliceHub-KSeF-Client/2',
        ];
        if ($m === 'POST' || $m === 'PUT' || $m === 'PATCH') {
            $headers[] = 'Content-Type: application/json; charset=UTF-8';
        }
        if ($bearer !== null && $bearer !== '') {
            $headers[] = 'Authorization: Bearer ' . $bearer;
        }

        $postFields = null;
        if ($m === 'POST' || $m === 'PUT' || $m === 'PATCH') {
            $payload = $jsonBody;
            if ($payload === null || $payload === []) {
                $payload = new \stdClass();
            }
            $postFields = json_encode($payload, JSON_UNESCAPED_UNICODE);
        }

        $lastResult = ['code' => 0, 'body' => '', 'json' => null];
        $maxAttempts = 4;

        for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
            $retryAfterSec = 0;
            $headerFn = static function ($ch, string $line) use (&$retryAfterSec): int {
                $len = strlen($line);
                if ($len <= 2) {
                    return $len;
                }
                if (stripos($line, 'Retry-After:') === 0) {
                    $v = trim(substr($line, 12));
                    if ($v !== '' && ctype_digit($v)) {
                        $retryAfterSec = min(120, (int) $v);
                    } elseif ($v !== '' && ($ts = strtotime($v)) !== false) {
                        $retryAfterSec = min(120, max(0, $ts - time()));
                    }
                }

                return $len;
            };

            $ch = curl_init($url);
            $opts = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_CUSTOMREQUEST  => $m,
                CURLOPT_HEADERFUNCTION => $headerFn,
            ];
            if ($postFields !== null) {
                $opts[CURLOPT_POSTFIELDS] = $postFields;
            }
            curl_setopt_array($ch, $opts);
            $body = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $cerr = curl_error($ch);
            curl_close($ch);

            if ($body === false) {
                return ['code' => 0, 'body' => 'curl: ' . $cerr, 'json' => null];
            }
            $bodyStr = (string) $body;
            $json = null;
            if (str_starts_with(ltrim($bodyStr), '{') || str_starts_with(ltrim($bodyStr), '[')) {
                $json = json_decode($bodyStr, true);
            }
            $lastResult = ['code' => $code, 'body' => $bodyStr, 'json' => $json];

            if ($code !== 429 || $attempt >= $maxAttempts - 1) {
                return $lastResult;
            }

            $sleepSec = $retryAfterSec > 0 ? $retryAfterSec : min(30, 2 * (1 + $attempt));
            sleep($sleepSec);
        }

        return $lastResult;
    }

    /**
     * @param array<string,mixed|null> $patch null usuwa klucz
     */
    private function persistCredentialPatch(array $patch): void
    {
        $st = $this->pdo->prepare(
            "SELECT credentials FROM sh_tenant_integrations
              WHERE tenant_id = :tid AND provider = 'ksef' LIMIT 1"
        );
        $st->execute([':tid' => $this->tenantId]);
        $raw = $st->fetchColumn();
        if (!is_string($raw) || $raw === '') {
            return;
        }
        $plain = \CredentialVault::decrypt($raw);
        if ($plain === null) {
            $plain = $raw;
        }
        $creds = json_decode($plain, true);
        if (!is_array($creds)) {
            $creds = [];
        }
        foreach ($patch as $k => $v) {
            if ($v === null) {
                unset($creds[$k]);
            } else {
                $creds[$k] = $v;
            }
        }
        $json = json_encode($creds, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }
        $enc = \CredentialVault::encrypt($json);
        $up = $this->pdo->prepare(
            "UPDATE sh_tenant_integrations SET credentials = :c, updated_at = NOW()
              WHERE tenant_id = :tid AND provider = 'ksef'"
        );
        $up->execute([':c' => $enc, ':tid' => $this->tenantId]);
    }

    private function formatHttpFailure(string $ctx, array $res): string
    {
        $code = (int) $res['code'];
        $snippet = substr(preg_replace('/\s+/', ' ', strip_tags($res['body'])), 0, 220);
        return "{$ctx}: HTTP {$code} — {$snippet}";
    }

    /**
     * Dolna granica `dateRange.from` — jak `to`, w pełnym ISO 8601 UTC (`…Z`), żeby MF nie interpretował
     * niespójnie mixu „data bez strefy” vs `to` w UTC (wtedy okno mogło ucinać świeże faktury).
     */
    private function buildMetadataDateFrom(?string $sinceDate): string
    {
        $now = time();
        $maxBack = self::METADATA_MAX_RANGE_DAYS * 86400;

        if (is_string($sinceDate) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $sinceDate)) {
            $fromTs = strtotime($sinceDate . ' 00:00:00 UTC');
            if ($fromTs === false) {
                $fromTs = $now - $maxBack;
            }
        } else {
            $fromTs = $now - $maxBack;
        }

        if ($now - $fromTs > $maxBack) {
            $fromTs = $now - $maxBack;
        }
        if ($fromTs > $now) {
            $fromTs = $now - 86400;
        }

        return gmdate('Y-m-d\TH:i:s\Z', $fromTs);
    }

    private static function normalizeNip(string $nip): ?string
    {
        $d = preg_replace('/\D+/', '', $nip);
        return is_string($d) && strlen($d) === 10 ? $d : null;
    }

    // =========================================================================
    // MOCK
    // =========================================================================

    private function mockQueryInbox(?string $sinceDate): array
    {
        $invoices = [];
        for ($i = 0; $i < 3; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            if ($sinceDate && $date < $sinceDate) {
                continue;
            }
            $invoices[] = [
                'ref_id'         => sprintf('MOCK-%s-%03d', date('Ymd', strtotime("-{$i} days")), 1 + $i),
                'supplier_nip'   => '5251234567',
                'invoice_number' => sprintf('FV/MOCK/%04d', 100 + $i),
                'issue_date'     => $date,
            ];
        }
        return ['success' => true, 'invoices' => $invoices];
    }

    private function mockFetchInvoiceXml(string $refId): array
    {
        $invoiceNo = 'FV/MOCK/' . substr(preg_replace('/\D+/', '', $refId), -6);
        $issueDate = date('Y-m-d');

        $st = $this->pdo->prepare('SELECT nip FROM sh_tenant WHERE id = :tid LIMIT 1');
        $st->execute([':tid' => $this->tenantId]);
        $buyerNipRaw = (string) ($st->fetchColumn() ?: '0000000000');
        $buyerNip = self::normalizeNip($buyerNipRaw) ?? '0000000000';

        $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<Faktura xmlns="http://crd.gov.pl/wzor/2023/06/29/12648/">
    <Naglowek>
        <KodFormularza kodSystemowy="FA (2)" wersjaSchemy="1-0E">FA</KodFormularza>
        <WariantFormularza>2</WariantFormularza>
        <DataWytworzeniaFa>{$issueDate}T10:00:00Z</DataWytworzeniaFa>
        <SystemInfo>KSeF Mock {$refId}</SystemInfo>
    </Naglowek>
    <Podmiot1>
        <DaneIdentyfikacyjne>
            <NIP>5251234567</NIP>
            <Nazwa>Eurocash Hurtownia (KSeF mock)</Nazwa>
        </DaneIdentyfikacyjne>
        <Adres>
            <KodKraju>PL</KodKraju>
            <AdresL1>ul. Mockowa 1</AdresL1>
            <AdresL2>60-100 Poznań</AdresL2>
        </Adres>
    </Podmiot1>
    <Podmiot2>
        <DaneIdentyfikacyjne>
            <NIP>{$buyerNip}</NIP>
            <Nazwa>Nabywca (mock)</Nazwa>
        </DaneIdentyfikacyjne>
        <Adres><KodKraju>PL</KodKraju><AdresL1>ul. Test 1</AdresL1></Adres>
    </Podmiot2>
    <Fa>
        <KodWaluty>PLN</KodWaluty>
        <P_1>{$issueDate}</P_1>
        <P_1M>POZNAN</P_1M>
        <P_2>{$invoiceNo}</P_2>
        <P_6>{$issueDate}</P_6>
        <FaWiersz>
            <NrWierszaFa>1</NrWierszaFa>
            <P_7>Mąka pszenna Caputo "00"</P_7>
            <P_8A>kg</P_8A><P_8B>10.0000</P_8B>
            <P_9A>4.5000</P_9A><P_11>45.00</P_11><P_12>5</P_12>
        </FaWiersz>
        <FaWiersz>
            <NrWierszaFa>2</NrWierszaFa>
            <P_7>Mozzarella Fior di Latte 1kg</P_7>
            <P_8A>kg</P_8A><P_8B>5.0000</P_8B>
            <P_9A>28.0000</P_9A><P_11>140.00</P_11><P_12>5</P_12>
        </FaWiersz>
        <P_13_2>185.00</P_13_2>
        <P_14_2>9.25</P_14_2>
        <P_15>194.25</P_15>
    </Fa>
</Faktura>
XML;
        return ['success' => true, 'xml' => $xml];
    }
}
