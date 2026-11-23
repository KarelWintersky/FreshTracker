<?php

declare(strict_types=1);

namespace FreshTracker\Access;

final readonly class AccessContext
{
    public function __construct(
        public string $group,
        public bool $canView,
        public bool $canEdit,
        public string $editPasswordScope,
    ) {
    }

    public static function denied(): self
    {
        return new self('none', false, false, '');
    }
}
