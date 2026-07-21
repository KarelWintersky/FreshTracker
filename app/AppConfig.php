<?php

namespace FreshTracker;

class AppConfig
{
    public static function getDefaultConfig(): array
    {
        $path_install = dirname(__DIR__);

        $path_database = $_SERVER['APP_DATABASE'] ?? $path_install . '/freshtracker.sqlite';

        return [
            'database' => [
                'driver'    => 'sqlite',
                'host'      => $path_database,
                'path'      => $path_database,
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
            // light | dark | minimal | warm
            'theme' => 'warm',
            'access'    =>  [
                'admin'     =>  [
                    '127.0.0.1'
                ],
                'view'      =>  [
                    '0.0.0.0/0'
                ]
            ],
        ];
    }

}