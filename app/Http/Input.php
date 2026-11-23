<?php

declare(strict_types=1);

namespace FreshTracker\Http;

use Arris\Request;

final class Input
{
    /** @return array<string, mixed> */
    public static function json(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || trim($raw) === '') {
            return [];
        }

        try {
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException('Некорректный JSON', 400);
        }

        if (!is_array($data)) {
            throw new HttpException('Ожидается JSON-объект', 400);
        }

        return $data;
    }

    public static function query(string $key, string $default = ''): string
    {
        $value = $_GET[$key] ?? $default;
        if (!is_string($value)) {
            return $default;
        }

        return Request::from($key, $_GET)
            ->trim()
            ->maxLength(200)
            ->default($default)
            ->asString();
    }
}
