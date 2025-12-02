<?php

namespace FreshTracker;

class Request
{
    public static function getInputData(): array
    {
        $input = file_get_contents('php://input');

        if (empty($input)) {
            return [];
        }

        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            Response::setError('Неверный формат JSON', 400);
        }

        return $data ?? [];
    }

}