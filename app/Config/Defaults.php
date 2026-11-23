<?php

declare(strict_types=1);

namespace FreshTracker\Config;

/**
 * Application defaults. Values from freshtracker.yaml override these keys.
 * `null` in a config file removes the key (Arris\AppConfig semantics).
 */
final class Defaults
{
    public static function all(): array
    {
        return [
            'app' => [
                'name'      => 'FreshTracker',
                'theme'     => 'day',
                'timezone'  => 'UTC',
                'debug'     => false,
            ],
            'database' => [
                'driver'                => 'sqlite',
                'path'                  => 'freshtracker.sqlite',
                'slow_query_threshold'  => 0.05,
            ],
            'access' => [
                'local' => [
                    'networks'  => ['127.0.0.1', '::1'],
                    'visible'   => true,
                    'password'  => '',
                ],
                'public' => [
                    'visible'   => 'always',
                    'password'  => '',
                    'editable'  => 'never',
                ],
            ],
            'security' => [
                'secret'            => '',
                'trusted_proxies'   => [],
            ],
            'logging' => [
                'path'  => 'logs',
                'level' => 'info',
            ],
            'defaults' => [
                'threshold_days' => 7,
            ],
            'features' => [
                // Keep an unfinished product form in sessionStorage across
                // navigation to the type-management page.
                'product_drafts' => false,
            ],
            'validation' => [
                'min_weight_g'          => 1,
                'max_weight_g'          => 5_000_000,
                'max_threshold_days'    => 365,
                'max_name_length'       => 255,
            ],
        ];
    }
}
