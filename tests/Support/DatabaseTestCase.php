<?php

declare(strict_types=1);

namespace FreshTracker\Tests\Support;

use FreshTracker\AppDatabase;
use FreshTracker\Migrations\Migrator;
use FreshTracker\Repositories\TypeRepository;
use PHPUnit\Framework\TestCase;

abstract class DatabaseTestCase extends TestCase
{
    protected AppDatabase $db;

    protected function setUp(): void
    {
        parent::setUp();

        $this->db = new AppDatabase(['driver' => 'sqlite', 'path' => ':memory:']);
        (new Migrator($this->db))->ensure();
    }

    protected function types(): TypeRepository
    {
        return new TypeRepository($this->db);
    }
}
