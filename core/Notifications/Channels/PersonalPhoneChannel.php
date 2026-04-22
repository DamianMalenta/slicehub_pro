<?php
declare(strict_types=1);

/**
 * PersonalPhoneChannel — wysyłka SMS przez telefon właściciela restauracji.
 *
 * Dwa tryby (pole `provider` w sh_notification_channels):
 *
 * 1. smsgateway_android — SMS Gateway for Android (https://sms-gate.app / github.com/capcom6/android-sms-gateway)
 *    Credentials JSON:
 *    {
 *      "provider":       "smsgateway_android",
 *      "base_url":       "https://api.sms-gate.app/3rdparty/v1",   // chmura — patrz docs.sms-gate.app
 *                        // lokalny serwer w apce: http://192.168.x.x:8080 (BEZ /3rdparty/v1)
 *      "username":       "twoj_login",
 *      "password":       "twoje_haslo",
 *      "webhook_secret": "secret_dla_callbackow_statusu",           // opcjonalny
 *      "smsgateway_mode":"auto"                                      // auto | local | cloud (opcjonalnie)
 *    }
 *
 * 2. generic_http — Generyczny HTTP POST (Tasker, MacroDroid, SMSForwarder)
 *    Credentials JSON:
 *    {
 *      "provider":    "generic_http",
 *      "url":         "http://192.168.1.100:8765/send",
 *      "bearer_token": "moj_token",
 *      "method":      "POST",            // POST (default)
 *      "payload_template": "{\"to\":\"{{to}}\",\"body\":\"{{body}}\"}",  // opcjonalny custom payload
 *      "timeout":     10
 *    }
 *
 * Rate limiting: NotificationDispatcher sprawdza rate_limit_per_hour / rate_limit_per_day.
 * Zalecane dla personal phone: 30/h, 100/d.
 */
class PersonalPhoneChannel implements ChannelInterface
{
    public static function getChannelType(): string
    {
        return 'personal_phone';
    }

    public function send(
        string $recipient,
        string $subject,
        string $body,
        array  $channelConfig,
        array  $ctx = []
    ): DeliveryResult {
        if ($recipient === '') {
            return DeliveryResult::fail('No recipient phone number.');
        }

        $cred     = $channelConfig['credentials'] ?? [];
        $provider = strtolower((string)($cred['provider'] ?? $channelConfig['provider'] ?? 'smsgateway_android'));

        return match ($provider) {
            'smsgateway_android' => $this->sendViaSmsGatewayAndroid($recipient, $body, $cred),
            'generic_http'       => $this->sendViaGenericHttp($recipient, $body, $cred),
            default              => DeliveryResult::fail("Unknown personal_phone provider: '{$provider}'"),
        };
    }

    // ─── Provider: smsgateway_android ────────────────────────────────────────

    private function sendViaSmsGatewayAndroid(string $phone, string $body, array $cred): DeliveryResult
    {
        $baseUrl  = rtrim((string)($cred['base_url'] ?? 'https://api.sms-gate.app/3rdparty/v1'), '/');
        $username = (string)($cred['username'] ?? '');
        $password = (string)($cred['password'] ?? '');
        $timeout  = (int)($cred['timeout'] ?? 15);

        if ($username === '' || $password === '') {
            return DeliveryResult::fail('smsgateway_android: missing username or password in credentials.');
        }

        $mode = $this->resolveSmsGatewayMode($baseUrl, $cred);
        // Chmura / private „3rdparty”: POST …/message + { message, phoneNumbers }
        // Lokalny serwer w apce: POST http://IP:8080/message + { textMessage: { text }, phoneNumbers } — docs.sms-gate.app/getting-started/local-server/
        if ($mode === 'local') {
            $baseRoot = preg_replace('#/3rdparty/v1/?$#i', '', $baseUrl) ?: $baseUrl;
            $baseRoot = preg_replace('#/message/?$#i', '', rtrim($baseRoot, '/')) ?: $baseRoot;
            $url      = rtrim($baseRoot, '/') . '/message';
            $payload  = json_encode([
                'textMessage'  => ['text' => $body],
                'phoneNumbers' => [$phone],
            ], JSON_UNESCAPED_UNICODE);
        } else {
            $url     = rtrim($baseUrl, '/') . '/message';
            $payload = json_encode([
                'message'      => $body,
                'phoneNumbers' => [$phone],
            ], JSON_UNESCAPED_UNICODE);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_USERPWD        => "{$username}:{$password}",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => str_starts_with($url, 'https://'),
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            return DeliveryResult::fail("smsgateway_android cURL error: {$curlErr}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return DeliveryResult::fail("smsgateway_android HTTP {$httpCode}: " . substr((string)$response, 0, 200));
        }

        $data = json_decode((string)$response, true);
        $messageId = $data['id'] ?? $data['messageId'] ?? null;

        return DeliveryResult::ok($messageId !== null ? (string)$messageId : null);
    }

    /**
     * @param  array<string,mixed> $cred
     */
    private function resolveSmsGatewayMode(string $baseUrl, array $cred): string
    {
        $explicit = strtolower((string)($cred['smsgateway_mode'] ?? ($cred['mode'] ?? 'auto')));
        if ($explicit === 'local' || $explicit === 'cloud') {
            return $explicit;
        }

        if (str_contains($baseUrl, 'api.sms-gate.app') || str_contains($baseUrl, '/3rdparty/')) {
            return 'cloud';
        }

        return 'local';
    }

    // ─── Provider: generic_http ───────────────────────────────────────────────

    private function sendViaGenericHttp(string $phone, string $body, array $cred): DeliveryResult
    {
        $url         = (string)($cred['url'] ?? '');
        $bearerToken = (string)($cred['bearer_token'] ?? '');
        $timeout     = (int)($cred['timeout'] ?? 10);

        if ($url === '') {
            return DeliveryResult::fail('generic_http: missing url in credentials.');
        }

        // Custom payload template (opcjonalne) z prostym str_replace
        $payloadTemplate = (string)($cred['payload_template'] ?? '');
        if ($payloadTemplate !== '') {
            $payload = str_replace(['{{to}}', '{{body}}'], [
                json_encode($phone, JSON_UNESCAPED_UNICODE),
                json_encode($body,  JSON_UNESCAPED_UNICODE),
            ], $payloadTemplate);
            // Usuń cudzysłowy dodane przez json_encode (template może już mieć "" wokół {{to}})
            $payload = str_replace(['"' . json_encode($phone, JSON_UNESCAPED_UNICODE) . '"'], [json_encode($phone)], $payload);
        } else {
            $payload = json_encode(['to' => $phone, 'body' => $body], JSON_UNESCAPED_UNICODE);
        }

        $headers = ['Content-Type: application/json'];
        if ($bearerToken !== '') {
            $headers[] = "Authorization: Bearer {$bearerToken}";
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => false, // dla LAN HTTP endpoints
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            return DeliveryResult::fail("generic_http cURL error: {$curlErr}");
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            return DeliveryResult::fail("generic_http HTTP {$httpCode}: " . substr((string)$response, 0, 200));
        }

        return DeliveryResult::ok();
    }
}
