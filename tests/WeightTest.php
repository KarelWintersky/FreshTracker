<?php

declare(strict_types=1);

namespace FreshTracker\Tests;

use FreshTracker\Domain\Weight;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class WeightTest extends TestCase
{
    public function testGramsStayGrams(): void
    {
        $weight = Weight::fromInput(900, 'g');

        self::assertSame(900, $weight->grams);
        self::assertSame('900 г', $weight->format());
    }

    public function testKilogramsConvertToGrams(): void
    {
        $weight = Weight::fromInput(1.5, 'kg');

        self::assertSame(1500, $weight->grams);
        self::assertSame('1,5 кг', $weight->format());
    }

    public function testWholeKilogramsAreFormattedWithoutDecimals(): void
    {
        self::assertSame('1 кг', Weight::fromGrams(1000)->format());
        self::assertSame('2 кг', Weight::fromGrams(2000)->format());
    }

    public function testZeroWeightIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Weight::fromInput(0, 'g');
    }

    public function testNegativeGramsAreRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Weight::fromGrams(-1);
    }
}
