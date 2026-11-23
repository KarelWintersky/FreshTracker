<?php

declare(strict_types=1);

namespace FreshTracker\Http;

final class Response
{
    public static function ok(mixed $data = null, int $status = 200): never
    {
        self::json(['status' => 'ok', 'data' => $data], $status);
    }

    /** @param array<string, mixed> $extra */
    public static function error(string $message, int $status = 400, array $extra = []): never
    {
        self::json(['status' => 'error', 'message' => $message] + $extra, $status);
    }

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-store');
        }

        try {
            $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $body = '{"status":"error","message":"Response encoding failed"}';
        }

        echo $body;
        exit;
    }
}
