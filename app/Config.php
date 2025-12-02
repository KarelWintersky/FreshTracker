<?php

namespace FreshTracker;

use PDO;

class Config
{
    public static function mergeWithDefaults(array $config): array
    {
        $defaults = [
            'database' => [
                'path' => 'freshtracker.sqlite',
                'options' => [
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
            ]
        ];

        return array_replace_recursive($defaults, $config);
    }

}