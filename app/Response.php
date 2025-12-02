<?php

namespace FreshTracker;

class Response
{
    public static string|array $data;
    public static int $code;

    public static bool $is_error = false;

    public static function set(string|array $data = '', $code = 200): void
    {
        self::$code = $code;
        self::$data = $data;
    }

    public static function setError($data = '', $code = 200): void
    {
        self::$code = $code;
        self::$data = $data;
        self::$is_error = true;
    }

    public static function send(): void
    {
        if (headers_sent()) {
            return;
        }

        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        http_response_code(self::$code);

        if (is_array(self::$data)) {
            self::$data = json_encode(self::$data, JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        }

        echo self::$data;
    }

    public static function sendCORS(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Content-Type: application/json');
        http_response_code(200);
    }

}