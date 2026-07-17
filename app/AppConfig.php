<?php

namespace FreshTracker;

use PDO;

class AppConfig
{
    public static function getDefaultConfig(): array
    {
        $path_install = dirname(__DIR__);

        $path_database = $_SERVER['APP_DATABASE'] ?? $path_install . '/freshtracker.sqlite';

        return [
            'database' => [
                'path'  => $path_database,
                'options'   => [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            ],
            'defaults' => [
                'threshold_days' => 7,
                'type' => 'разное'
            ],
            'validation' => [
                'max_weight' => 1000,
                'max_threshold_days' => 365,
                'min_weight' => 0.001
            ],
            'theme' => 'warm'
        ];
    }

}