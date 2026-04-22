<?php

declare(strict_types=1);

/**
 * Minimal HS256 JWT encode/decode (no external dependencies).
 */
class JwtProvider
{
    public static function encode(array $payload, string $secret): string
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('JWT secret must not be empty.');
        }

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $h      = self::base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR));
        $p      = self::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $sig    = self::sign($h . '.' . $p, $secret);

        return $h . '.' . $p . '.' . $sig;
    }

    /**
     * @return array<string, mixed>
     */
    public static function decode(string $token, string $secret): array
    {
        if ($secret === '') {
            throw new \InvalidArgumentException('JWT secret must not be empty.');
        }

        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \Exception('Invalid Signature');
        }

        [$h, $p, $sig] = $parts;
        if ($h === '' || $p === '' || $sig === '') {
            throw new \Exception('Invalid Signature');
        }

        $expected = self::sign($h . '.' . $p, $secret);
        if (!hash_equals($expected, $sig)) {
            throw new \Exception('Invalid Signature');
        }

        try {
            $json = self::base64UrlDecode($p);
        } catch (\Throwable) {
            throw new \Exception('Invalid Signature');
        }

        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \Exception('Invalid Signature');
        }

        if (!is_array($payload)) {
            throw new \Exception('Invalid Signature');
        }

        if (isset($payload['exp']) && is_numeric($payload['exp']) && (int)$payload['exp'] < time()) {
            throw new \Exception('Token Expired');
        }

        return $payload;
    }

    private static function sign(string $data, string $secret): string
    {
        $raw = hash_hmac('sha256', $data, $secret, true);

        return self::base64UrlEncode($raw);
    }

    private static function base64UrlEncode(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $b64): string
    {
        $padded = strtr($b64, '-_', '+/');
        $mod    = strlen($padded) % 4;
        if ($mod > 0) {
            $padded .= str_repeat('=', 4 - $mod);
        }
        $raw = base64_decode($padded, true);
        if ($raw === false) {
            throw new \RuntimeException('Invalid base64url segment.');
        }

        return $raw;
    }
}
