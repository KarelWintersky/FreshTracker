<?php

declare(strict_types=1);

namespace FreshTracker\Tests;

use FreshTracker\AppDatabase;
use FreshTracker\Migrations\Migrator;
use PHPUnit\Framework\TestCase;

final class MigrationTest extends TestCase
{
    public function testLegacySchemaIsRebuiltIntoGramsAndStatuses(): void
    {
        $db = $this->db();

        $db->execute('CREATE TABLE products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            weight REAL NOT NULL,
            expiry_date TEXT NOT NULL,
            type TEXT NOT NULL,
            threshold_days INTEGER DEFAULT 7,
            is_deleted BOOLEAN DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            deleted_at DATETIME NULL
        )');

        $db->execute(
            "INSERT INTO products (name, weight, expiry_date, type, is_deleted, deleted_at) VALUES
             ('Крупа', 0.9, '2026-12-01', 'крупы', 0, NULL),
             ('Масло', 1.5, '2026-01-01', 'масло', 1, '2026-02-01 10:00:00')"
        );

        (new Migrator($db))->ensure();

        $columns = $db->getDriver()->tableColumns('products');
        self::assertArrayHasKey('weight_g', $columns);
        self::assertArrayHasKey('status', $columns);
        self::assertArrayHasKey('closed_at', $columns);
        self::assertArrayNotHasKey('is_deleted', $columns);

        $rows = $db->fetchAll('SELECT * FROM products ORDER BY id ASC');
        self::assertCount(2, $rows);
        self::assertSame(900, (int) $rows[0]['weight_g']);
        self::assertSame('active', $rows[0]['status']);
        self::assertSame(1500, (int) $rows[1]['weight_g']);
        self::assertSame('disposed', $rows[1]['status']);
        self::assertGreaterThan(0, (int) $rows[0]['type_id']);
        self::assertSame(1, (int) $db->fetchValue('PRAGMA user_version'));
    }

    public function testMigrationIsIdempotent(): void
    {
        $db = $this->db();
        $migrator = new Migrator($db);

        $migrator->ensure();
        $migrator->ensure();

        self::assertSame(8, (int) $db->fetchValue('SELECT COUNT(*) FROM types'));
        self::assertSame(1, (int) $db->fetchValue('PRAGMA user_version'));
    }

    public function testDaysRemainingComparesDatesNotTimestamps(): void
    {
        $db = $this->db();
        (new Migrator($db))->ensure();

        $typeId = (int) $db->fetchValue('SELECT id FROM types WHERE slug = ?', ['разное']);
        $db->execute(
            "INSERT INTO products (name, type_id, weight_g, expiry_date, threshold_days, status, created_at, updated_at)
             VALUES ('Сегодня', ?, 100, date('now'), 7, 'active', datetime('now'), datetime('now'))",
            [$typeId]
        );

        $days = $db->fetchValue('SELECT ' . $db->sqlDaysRemaining('expiry_date') . ' FROM products');

        self::assertSame(0, (int) $days);
    }

    private function db(): AppDatabase
    {
        return new AppDatabase(['driver' => 'sqlite', 'path' => ':memory:']);
    }
}
