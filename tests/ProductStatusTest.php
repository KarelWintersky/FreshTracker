<?php

declare(strict_types=1);

namespace FreshTracker\Tests;

use FreshTracker\Domain\ProductStatus;
use PHPUnit\Framework\TestCase;

final class ProductStatusTest extends TestCase
{
    public function testStatusLabelsAndClosedFlag(): void
    {
        self::assertSame('В запасе', ProductStatus::Active->label());
        self::assertSame('Использован', ProductStatus::Used->label());
        self::assertSame('Выброшен', ProductStatus::Disposed->label());

        self::assertFalse(ProductStatus::Active->isClosed());
        self::assertTrue(ProductStatus::Used->isClosed());
        self::assertTrue(ProductStatus::Disposed->isClosed());
    }
}
