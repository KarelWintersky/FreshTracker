<?php

namespace FreshTracker\Units;

use FreshTracker\Controllers\ResponseController;

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
            ResponseController::setError('Неверный формат JSON', 400);
        }

        return $data ?? [];
    }

}