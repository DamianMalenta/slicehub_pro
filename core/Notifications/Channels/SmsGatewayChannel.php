<?php
declare(strict_types=1);

/**
 * SmsGatewayChannel — wysyłka SMS przez komercyjną bramkę.
 *
 * Obsługiwane providery (pole `provider` w credentials_json):
 *
 * 1. smsapi_pl — SMSAPI.pl (https://www.smsapi.pl)
 *    Credentials JSON:
 *    {
 *      "provider":    "smsapi_pl",
 *      "token":       "twoj_oauth_token",
 *      "sender":      "PizzaForno",     // opcjonalne (alfanumeryczny nadawca)
 *      "test":        false             // true = tryb testowy (nie wysyła, zwraca OK)
 *    }
 *
 * 2. twilio — Twilio (https://www.twilio.com)
 *    Credentials JSON:
 *    {
 *      "provider":       "twilio",
 *      "account_sid":    "ACxxxxxxxx",
 *      "auth_token":     "xxxxxxxx",
 *      "from_number":    "+48XXXXXXXXX"  // Twilio numer nadawcy
 *    }
 */
class SmsGatewayChannel implements ChannelInterface
{
    public static function getChannelType(): string
    {
        return 'sms_gateway';
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
        $provider = strtolower((string)($cred['provider'] ?? $channelConfig['provider'] ?? 'smsapi_pl'));

        return match ($provider) {
            'smsapi_pl' => $this->sendViaSmsapiPl($recipient, $body, $cred),
            'twilio'    => $this->sendViaTwilio($recipient, $body, $cred),
            default     => DeliveryResult::fail("Unknown sms_gateway provider: '{$provider}'"),
        };
    }

    // ─── Provider: smsapi_pl ─────────────────────────────────────────────────

    private function sendViaSmsapiPl(string $phone, string $body, array $cred): DeliveryResult
    {
        $token  = (string)($cred['token'] ?? '');
        $sender = (string)($cred['sender'] ?? '');
        $test   = !empty($cred['test']);

        if ($token === '') {
            return DeliveryResult::fail('smsapi_pl: missing token in credentials.');
        }

        $params = [
            'to'       => $phone,
            'message'  => $body,
            'encoding' => 'utf-8',
            'format'   => 'json',
        ];

        if ($sender !== '') {
            $params['from'] = $sender;
        }
        if ($test) {
            $params['test'] = 1;
        }

        $ch = curl_init('https://api.smsapi.pl/sms.do');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            return DeliveryResult::fail("smsapi_pl cURL error: {$curlErr}");
        }

        $data = json_decode((string)$response, true) ?? [];

        if ($httpCode < 200 || $httpCode >= 300 || isset($data['error'])) {
            $errCode = $data['error'] ?? $data['code'] ?? $httpCode;
            $errMsg  = $data['message'] ?? $data['invalid_numbers'][0]['message'] ?? (string)$response;
            return DeliveryResult::fail("smsapi_pl error {$errCode}: " . substr($errMsg, 0, 200));
        }

        $msgId = $data['list'][0]['id'] ?? $data['id'] ?? null;
        // Szacowany koszt: SMSAPI zwraca `points` (ułamki kredytów) — konwertujemy na grosze jeśli dostępne
        $costGrosze = null;
        if (isset($data['list'][0]['points'])) {
            $costGrosze = (int)round((float)$data['list'][0]['points'] * 100);
        }

        return DeliveryResult::ok($msgId ? (string)$msgId : null, $costGrosze);
    }

    // ─── Provider: twilio ────────────────────────────────────────────────────

    private function sendViaTwilio(string $phone, string $body, array $cred): DeliveryResult
    {
        $accountSid = (string)($cred['account_sid'] ?? '');
        $authToken  = (string)($cred['auth_token']  ?? '');
        $fromNumber = (string)($cred['from_number'] ?? '');

        if ($accountSid === '' || $authToken === '' || $fromNumber === '') {
            return DeliveryResult::fail('twilio: missing account_sid, auth_token, or from_number in credentials.');
        }

        $url     = "https://api.twilio.com/2010-04-01/Accounts/{$accountSid}/Messages.json";
        $payload = http_build_query(['To' => $phone, 'From' => $fromNumber, 'Body' => $body]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_USERPWD        => "{$accountSid}:{$authToken}",
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr !== '') {
            return DeliveryResult::fail("twilio cURL error: {$curlErr}");
        }

        $data = json_decode((string)$response, true) ?? [];

        if ($httpCode < 200 || $httpCode >= 300 || isset($data['code'])) {
            $errCode = $data['code'] ?? $httpCode;
            $errMsg  = $data['message'] ?? (string)$response;
            return DeliveryResult::fail("twilio error {$errCode}: " . substr($errMsg, 0, 200));
        }

        $msgSid = $data['sid'] ?? null;
        return DeliveryResult::ok($msgSid ? (string)$msgSid : null);
    }
}
