<?php

declare(strict_types=1);

namespace FreshTracker\Access;

final class EditSession
{
    public function __construct(
        private readonly string $secret,
        private readonly int $ttl = 2_592_000,
        private readonly string $cookieName = 'ft_edit',
    ) {
    }

    public function hasGrant(string $scope): bool
    {
        $value = $_COOKIE[$this->cookieName] ?? '';
        if (!is_string($value) || $value === '') {
            return false;
        }

        $payload = $this->verify($value);
        if ($payload === null) {
            return false;
        }

        return ($payload['s'] ?? '') === $scope
            && (int) ($payload['e'] ?? 0) >= time();
    }

    public function grant(string $scope): void
    {
        $expires = time() + $this->ttl;
        $this->writeCookie($this->sign(['s' => $scope, 'e' => $expires]), $expires);
    }

    public function revoke(): void
    {
        $this->writeCookie('', time() - 3600);
    }

    /** @return array<string, mixed>|null */
    private function verify(string $value): ?array
    {
        $parts = explode('.', $value, 2);
        if (count($parts) !== 2) {
            return null;
        }

        [$encodedPayload, $signature] = $parts;
        $payload = self::base64UrlDecode($encodedPayload);
        if ($payload === null) {
            return null;
        }

        $expected = hash_hmac('sha256', $payload, $this->secret, true);
        if (!hash_equals(self::base64UrlEncode($expected), $signature)) {
            return null;
        }

        try {
            $decoded = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /** @param array<string, mixed> $payload */
    private function sign(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR);
        $signature = hash_hmac('sha256', $json, $this->secret, true);

        return self::base64UrlEncode($json) . '.' . self::base64UrlEncode($signature);
    }

    private function writeCookie(string $value, int $expires): void
    {
        if (headers_sent()) {
            return;
        }

        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        setcookie($this->cookieName, $value, [
            'expires'   => $expires,
            'path'      => '/',
            'httponly'  => true,
            'samesite'  => 'Lax',
            'secure'    => $secure,
        ]);
    }

    private static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private static function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
