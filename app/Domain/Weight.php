<?php

declare(strict_types=1);

namespace FreshTracker\Domain;

use InvalidArgumentException;

final class Weight
{
    private function __construct(public readonly int $grams)
    {
    }

    public static function fromGrams(int $grams): self
    {
        if ($grams < 1) {
            throw new InvalidArgumentException('Weight must be positive');
        }

        return new self($grams);
    }

    public static function fromInput(mixed $value, string $unit = 'g'): self
    {
        if (!is_numeric($value)) {
            throw new InvalidArgumentException('Weight is not numeric');
        }

        $number = (float) $value;
        if ($number <= 0) {
            throw new InvalidArgumentException('Weight must be positive');
        }

        $grams = strtolower($unit) === 'kg'
            ? (int) round($number * 1000)
            : (int) round($number);

        return self::fromGrams($grams);
    }

    public function format(): string
    {
        if ($this->grams >= 1000) {
            $kilograms = rtrim(rtrim(number_format($this->grams / 1000, 3, ',', ''), '0'), ',');

            return $kilograms . ' кг';
        }

        return $this->grams . ' г';
    }
}
