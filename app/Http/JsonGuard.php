<?php

declare(strict_types=1);

namespace FreshTracker\Http;

final class JsonGuard
{
    /** Rejects cross-site writes and non-JSON bodies. */
    public static function assertSameOrigin(): void
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        if (in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
            if (!str_starts_with($contentType, 'application/json')) {
                throw new HttpException('Ожидается Content-Type: application/json', 415);
            }
        }

        $site = (string) ($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
        if ($site !== '' && !in_array($site, ['same-origin', 'none'], true)) {
            throw new HttpException('Кросс-доменный запрос запрещён', 403);
        }

        $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? '');
        if ($origin !== '') {
            $host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
            $originHost = parse_url($origin, PHP_URL_HOST);
            $originPort = parse_url($origin, PHP_URL_PORT);

            $authority = is_string($originHost) ? strtolower($originHost) : '';
            if ($originPort !== null) {
                $authority .= ':' . $originPort;
            }

            if ($authority === '' || !hash_equals($host, $authority)) {
                throw new HttpException('Кросс-доменный запрос запрещён', 403);
            }
        }
    }
}
