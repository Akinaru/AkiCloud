<?php

namespace App\Service;

use App\Entity\Site;
use App\Entity\User;
use App\Repository\SettingRepository;

class SiteSecurityTokenService
{
    private const TOKEN_TTL_SECONDS = 300;

    public function __construct(
        private SettingRepository $settingRepository,
        private string $appSecret
    ) {
    }

    public function createToken(User $user, Site $site, string $host): string
    {
        $now = time();
        $payload = [
            'sub' => (int) $user->getId(),
            'email' => (string) $user->getEmail(),
            'site_id' => (int) $site->getId(),
            'site_host' => mb_strtolower($host),
            'iat' => $now,
            'exp' => $now + self::TOKEN_TTL_SECONDS,
            'nonce' => bin2hex(random_bytes(8)),
        ];

        $payloadJson = json_encode($payload, JSON_THROW_ON_ERROR);
        $payloadEncoded = $this->base64UrlEncode($payloadJson);
        $signature = $this->sign($payloadEncoded);

        return $payloadEncoded . '.' . $signature;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function validateToken(string $token, ?string $expectedHost = null): ?array
    {
        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$payloadEncoded, $signature] = $parts;
        if ($payloadEncoded === '' || $signature === '') {
            return null;
        }

        $expectedSignature = $this->sign($payloadEncoded);
        if (!hash_equals($expectedSignature, $signature)) {
            return null;
        }

        $payloadJson = $this->base64UrlDecode($payloadEncoded);
        if ($payloadJson === null) {
            return null;
        }

        try {
            $payload = json_decode($payloadJson, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        if (!is_array($payload)) {
            return null;
        }

        $exp = isset($payload['exp']) ? (int) $payload['exp'] : 0;
        if ($exp <= 0 || $exp < time()) {
            return null;
        }

        if ($expectedHost !== null) {
            $tokenHost = mb_strtolower((string) ($payload['site_host'] ?? ''));
            if ($tokenHost === '' || $tokenHost !== mb_strtolower($expectedHost)) {
                return null;
            }
        }

        return $payload;
    }

    public function getApiKey(): string
    {
        $configured = (string) $this->settingRepository->getValue('site_security_api_key', '');
        if ($configured !== '') {
            return $configured;
        }

        $env = $_ENV['SITE_SECURITY_API_KEY'] ?? $_SERVER['SITE_SECURITY_API_KEY'] ?? '';

        return is_string($env) ? trim($env) : '';
    }

    private function sign(string $payloadEncoded): string
    {
        $secret = $this->getSigningSecret();
        $signatureRaw = hash_hmac('sha256', $payloadEncoded, $secret, true);

        return $this->base64UrlEncode($signatureRaw);
    }

    private function getSigningSecret(): string
    {
        $configured = (string) $this->settingRepository->getValue('site_security_signing_secret', '');
        if ($configured !== '') {
            return $configured;
        }

        $env = $_ENV['SITE_SECURITY_SIGNING_SECRET'] ?? $_SERVER['SITE_SECURITY_SIGNING_SECRET'] ?? '';
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }

        return $this->appSecret;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
