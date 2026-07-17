<?php

namespace FreshTracker\Controllers;

use FreshTracker\App;

class ConfigController
{
    public static function get(): void
    {
        ResponseController::set([
            'theme' => App::config('theme', null, 'light'),
        ]);
    }
}
