<?php

declare(strict_types=1);

namespace SliceHub\Ksef;

require_once __DIR__ . '/../CredentialVault.php';

/**
 * SliceHub — KSeF API Client
 *
 * Klient REST do Krajowego Systemu e-Faktur (KSeF) Ministerstwa Finansów.
 *
 * Sandbox URL: https://ksef-test.mf.gov.pl/api
 * Prod URL:    https://ksef.mf.gov.pl/api
 *
 * Auth: KSeF Token (najprostsza opcja, generowany w panelu podatnika
 * na podatki.gov.pl). Alternatywa: certyfikat kwalifikowany — nie wspierane
 * w MVP F4.
 *
 * Mock mode (F4 testing): gdy `environment === 'mock'`, klient zwraca
 * fixtures z `tests/ksef/` zamiast realnych HTTP requestów. Pozwala to
 * przetestować całą maszynerię bez prawdziwego KSeF Tokenu.
 *
 * Konstytucja v5 § Prawo IV (Zero Zaufania):
 *   Token NIGDY nie ląduje w logach. CredentialVault::encrypt zapisuje go
 *   szyfrowane at-rest w sh_tenant_integrations.credentials.
 *
 * Konstytucja v5 § Prawo VI Snajper:
 *   Każde query SQL z tenant_id = :tid. Wszystkie JOIN cross-silo (sh_/wh_)
 *   przez klucze znakowe (SKU, NIP).
 *
 * Wzorzec wykorzystany przez `scripts/worker_ksef_inbox.php` (cron 5-15 min
 * albo HTTP trigger) — fetchuje nowe faktury z KSeF i INSERT-uje do
 * sh_ksef_invoices (m046).
 *
 * Sesja F4 · 2026-05-11.
 */
class Client
{
    public const ENV_SANDBOX = 'sandbox';
    public const ENV_PROD    = 'prod';
    public const ENV_MOCK    = 'mock';

    private const URLS = [
        'sandbox' => 'https://ksef-test.mf.gov.pl/api',
        'prod'    => 'https://ksef.mf.gov.pl/api',
        'mock'    => 'mock://internal',
    ];

    private \PDO $pdo;
    private int $tenantId;
    private string $environment = self::ENV_MOCK;
    private ?string $token = null;
    private string $baseUrl = '';
    private int $timeout = 30;

    public function __construct(\PDO $pdo, int $tenantId)
    {
        $this->pdo = $pdo;
        $this->tenantId = $tenantId;
        $this->loadConfig();
    }

    /**
     * Wczytaj config z sh_tenant_integrations (provider='ksef').
     * Token decrypt przez CredentialVault.
     */
    private function loadConfig(): void
    {
        $st = $this->pdo->prepare(
            "SELECT api_base_url, credentials, is_active
               FROM sh_tenant_integrations
              WHERE tenant_id = :tid AND provider = 'ksef'
              LIMIT 1"
        );
        $st->execute([':tid' => $this->tenantId]);
        $row = $st->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            // Brak konfiguracji — defaultowo mock mode (developer-friendly)
            $this->environment = self::ENV_MOCK;
            $this->baseUrl = self::URLS['mock'];
            return;
        }

        $credsRaw = (string) ($row['credentials'] ?? '');
        if ($credsRaw !== '') {
            $plain = \CredentialVault::decrypt($credsRaw);
            if ($plain === null) {
                $plain = $credsRaw; // fallback: niezaszyfrowany JSON
            }
            $creds = json_decode($plain, true);
            if (is_array($creds)) {
                $this->environment = (string) ($creds['environment'] ?? self::ENV_MOCK);
                $this->token = (string) ($creds['token'] ?? '');
                if ($this->token === '') $this->token = null;
            }
        }

        $this->baseUrl = (string) ($row['api_base_url'] ?? '');
        if ($this->baseUrl === '') {
            $this->baseUrl = self::URLS[$this->environment] ?? self::URLS['mock'];
        }
    }

    public function getEnvironment(): string { return $this->environment; }
    public function isMockMode(): bool { return $this->environment === self::ENV_MOCK; }
    public function hasToken(): bool { return $this->token !== null && $this->token !== ''; }

    /**
     * Test connection do KSeF API. Mock: zawsze OK.
     *
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
        if (!$this->hasToken()) {
            return [
                'success'     => false,
                'environment' => $this->environment,
                'message'     => 'Brak KSeF Token. Skonfiguruj w Settings → KSeF.',
            ];
        }

        // /online/Session/Status — endpoint sprawdzający token
        $res = $this->httpGet('/online/Session/Status');
        if ($res['code'] >= 200 && $res['code'] < 300) {
            return [
                'success'     => true,
                'environment' => $this->environment,
                'message'     => "Połączenie z {$this->environment} OK.",
                'http_code'   => $res['code'],
            ];
        }
        return [
            'success'     => false,
            'environment' => $this->environment,
            'message'     => "Test connection failed: HTTP {$res['code']} — " . substr($res['body'], 0, 200),
            'http_code'   => $res['code'],
        ];
    }

    /**
     * Lista referencyjnych ID faktur do pobrania (od ostatniego polla).
     *
     * @param string|null $sinceDate ISO date — wszystkie od daty
     * @param string|null $lastSeenId — paginacja
     * @return array{success:bool, invoices: list<array{ref_id:string, supplier_nip:string, invoice_number:string, issue_date:string}>, message?:string}
     */
    public function queryInbox(?string $sinceDate = null, ?string $lastSeenId = null): array
    {
        if ($this->isMockMode()) {
            return $this->mockQueryInbox($sinceDate);
        }
        if (!$this->hasToken()) {
            return ['success' => false, 'invoices' => [], 'message' => 'Brak KSeF Token.'];
        }

        $params = [];
        if ($sinceDate !== null) $params['DateFrom'] = $sinceDate;
        if ($lastSeenId !== null) $params['LastInvoiceRef'] = $lastSeenId;
        $qs = $params !== [] ? '?' . http_build_query($params) : '';

        $res = $this->httpGet('/online/Query/Invoice' . $qs);
        if ($res['code'] !== 200) {
            return ['success' => false, 'invoices' => [], 'message' => "HTTP {$res['code']}"];
        }
        $json = json_decode($res['body'], true);
        if (!is_array($json)) {
            return ['success' => false, 'invoices' => [], 'message' => 'Invalid JSON response'];
        }

        // KSeF response format: { "invoices": [{"invoiceReferenceNumber":"X", ...}] }
        $invoices = [];
        foreach (($json['invoices'] ?? []) as $inv) {
            $invoices[] = [
                'ref_id'         => (string) ($inv['invoiceReferenceNumber'] ?? ''),
                'supplier_nip'   => (string) ($inv['issuedBy']['identifier'] ?? ''),
                'invoice_number' => (string) ($inv['invoiceNumber'] ?? ''),
                'issue_date'     => (string) ($inv['issueDate'] ?? ''),
            ];
        }
        return ['success' => true, 'invoices' => $invoices];
    }

    /**
     * Pobierz pełny FA(2) XML jednej faktury.
     *
     * @return array{success:bool, xml?:string, message?:string}
     */
    public function fetchInvoiceXml(string $refId): array
    {
        if ($this->isMockMode()) {
            return $this->mockFetchInvoiceXml($refId);
        }
        if (!$this->hasToken()) {
            return ['success' => false, 'message' => 'Brak KSeF Token.'];
        }

        $res = $this->httpGet('/online/Invoice/Get/' . rawurlencode($refId));
        if ($res['code'] !== 200) {
            return ['success' => false, 'message' => "HTTP {$res['code']}"];
        }
        // KSeF zwraca XML inline (Content-Type: application/xml)
        return ['success' => true, 'xml' => $res['body']];
    }

    // =========================================================================
    // HTTP helpers (cURL)
    // =========================================================================

    private function httpGet(string $path): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_HTTPHEADER     => [
                'Accept: application/xml, application/json',
                'SessionToken: ' . ($this->token ?? ''),
            ],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            return ['code' => 0, 'body' => 'curl error: ' . $err];
        }
        return ['code' => $code, 'body' => (string) $body];
    }

    // =========================================================================
    // MOCK MODE — fixtures z deterministyczną listą
    // =========================================================================

    private function mockQueryInbox(?string $sinceDate): array
    {
        // Symulujemy 1 nową fakturę dziennie (od dziś -3 dni)
        $invoices = [];
        for ($i = 0; $i < 3; $i++) {
            $date = date('Y-m-d', strtotime("-{$i} days"));
            if ($sinceDate && $date < $sinceDate) continue;
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
        // Stała mockowa faktura. Numer + data wpisana z $refId żeby każdy poll
        // dawał inny invoice_number — dedup w worker zadziała przez ksef_reference_id.
        $invoiceNo = 'FV/MOCK/' . substr(preg_replace('/\D+/', '', $refId), -6);
        $issueDate = date('Y-m-d');

        // Pobierz NIP nabywcy z sh_tenant — żeby buyer pasował i parser nie rzucił WRONG_BUYER_NIP
        $st = $this->pdo->prepare("SELECT nip FROM sh_tenant WHERE id = :tid LIMIT 1");
        $st->execute([':tid' => $this->tenantId]);
        $buyerNip = (string) ($st->fetchColumn() ?: '0000000000');

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
