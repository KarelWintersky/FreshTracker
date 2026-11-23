<?php

declare(strict_types=1);

namespace FreshTracker\Domain;

enum ProductStatus: string
{
    case Active = 'active';
    case Used = 'used';
    case Disposed = 'disposed';

    public function label(): string
    {
        return match ($this) {
            self::Active   => 'В запасе',
            self::Used     => 'Использован',
            self::Disposed => 'Выброшен',
        };
    }

    public function isClosed(): bool
    {
        return $this !== self::Active;
    }
}
