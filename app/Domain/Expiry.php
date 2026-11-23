<?php

declare(strict_types=1);

namespace FreshTracker\Domain;

use DateTimeImmutable;

final class Expiry
{
    private const FORMATS = ['!Y-m-d', '!d.m.Y', '!d/m/Y', '!d-m-Y', '!Y/m/d'];

    /** Accepts Y-m-d and common Russian formats; returns Y-m-d or null. */
    public static function normalize(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);
        if ($value === '') {
            return null;
        }

        foreach (self::FORMATS as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false && $date->format(substr($format, 1)) === $value) {
                return $date->format('Y-m-d');
            }
        }

        return null;
    }

    /** Whole days between the given date and today. 0 = expires today. */
    public static function daysLeft(string $date, ?DateTimeImmutable $today = null): int
    {
        $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
        if ($expiry === false) {
            return 0;
        }

        $today ??= new DateTimeImmutable('today');
        $today = $today->setTime(0, 0);

        $diff = $today->diff($expiry);
        $days = (int) $diff->days;

        return $diff->invert === 1 ? -$days : $days;
    }

    /** Y-m-d for a date N days from today (negative allowed). */
    public static function fromDays(int $days, ?DateTimeImmutable $today = null): string
    {
        $today ??= new DateTimeImmutable('today');
        $sign = $days < 0 ? '-' : '+';

        return $today->modify($sign . abs($days) . ' days')->format('Y-m-d');
    }
}
