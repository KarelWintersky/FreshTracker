<?php

declare(strict_types=1);

namespace FreshTracker\Tests;

use DateTimeImmutable;
use FreshTracker\Domain\Expiry;
use PHPUnit\Framework\TestCase;

final class ExpiryTest extends TestCase
{
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();

        $this->today = new DateTimeImmutable('2026-01-01');
    }

    public function testTodayIsZeroDaysLeft(): void
    {
        self::assertSame(0, Expiry::daysLeft('2026-01-01', $this->today));
    }

    public function testTomorrowIsOneDayLeft(): void
    {
        self::assertSame(1, Expiry::daysLeft('2026-01-02', $this->today));
    }

    public function testYesterdayIsMinusOneDay(): void
    {
        self::assertSame(-1, Expiry::daysLeft('2025-12-31', $this->today));
    }

    public function testDateNormalizationAcceptsCommonFormats(): void
    {
        self::assertSame('2026-12-31', Expiry::normalize('31.12.2026'));
        self::assertSame('2026-12-31', Expiry::normalize('2026-12-31'));
        self::assertSame('2026-12-31', Expiry::normalize('31/12/2026'));
        self::assertNull(Expiry::normalize('31.13.2026'));
        self::assertNull(Expiry::normalize(''));
    }

    public function testFromDaysBuildsADate(): void
    {
        self::assertSame('2026-01-04', Expiry::fromDays(3, $this->today));
        self::assertSame('2025-12-31', Expiry::fromDays(-1, $this->today));
    }
}
