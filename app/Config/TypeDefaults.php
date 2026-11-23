<?php

declare(strict_types=1);

namespace FreshTracker\Config;

final class TypeDefaults
{
    /**
     * Default product types. `expiry_days` is a quick-add hint (not a rule),
     * `threshold_days` drives the warning colour.
     *
     * @return list<array{slug: string, title: string, expiry_days: int, threshold_days: int, weight_g: int|null}>
     */
    public static function all(): array
    {
        return [
            ['slug' => 'разное',     'title' => 'Разное',    'expiry_days' => 30,  'threshold_days' => 7, 'weight_g' => 1000],
            ['slug' => 'крупы',      'title' => 'Крупы',     'expiry_days' => 365, 'threshold_days' => 7, 'weight_g' => 900],
            ['slug' => 'макароны',   'title' => 'Макароны',  'expiry_days' => 180, 'threshold_days' => 7, 'weight_g' => 500],
            ['slug' => 'консервы',   'title' => 'Консервы',  'expiry_days' => 365, 'threshold_days' => 7, 'weight_g' => 400],
            ['slug' => 'масло',      'title' => 'Масло',     'expiry_days' => 30,  'threshold_days' => 7, 'weight_g' => 900],
            ['slug' => 'мука',       'title' => 'Мука',      'expiry_days' => 365, 'threshold_days' => 7, 'weight_g' => 1000],
            ['slug' => 'специи',     'title' => 'Специи',    'expiry_days' => 180, 'threshold_days' => 7, 'weight_g' => 100],
            ['slug' => 'чай_кофе',   'title' => 'Чай/Кофе',  'expiry_days' => 180, 'threshold_days' => 7, 'weight_g' => 250],
        ];
    }
}
