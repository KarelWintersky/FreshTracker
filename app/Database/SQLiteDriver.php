<?php

declare(strict_types=1);

namespace FreshTracker\Database;

use PDO;

final class SQLiteDriver extends DatabaseDriver
{
    public function name(): string
    {
        return 'sqlite';
    }

    protected function configure(): void
    {
        $this->connector->exec('PRAGMA journal_mode = WAL');
        $this->connector->exec('PRAGMA foreign_keys = ON');
        $this->connector->exec('PRAGMA busy_timeout = 5000');
        $this->connector->exec('PRAGMA synchronous = NORMAL');
    }

    public function sqlNow(): string
    {
        return "datetime('now')";
    }

    public function sqlDaysRemaining(string $dateColumn): string
    {
        return "CAST(julianday(date({$dateColumn})) - julianday(date('now')) AS INTEGER)";
    }

    public function createTables(): void
    {
        $this->createAuxTables();

        $this->connector->exec(self::productsTableSql('products'));
        foreach ($this->indexStatements() as $sql) {
            $this->connector->exec($sql);
        }
    }

    /**
     * Tables that do not depend on the products schema.
     * Used before rebuilding a legacy products table.
     */
    public function createAuxTables(): void
    {
        foreach ($this->auxStatements() as $sql) {
            $this->connector->exec($sql);
        }
    }

    /** @return list<string> */
    private function auxStatements(): array
    {
        return [
            "CREATE TABLE IF NOT EXISTS types (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                slug TEXT NOT NULL UNIQUE,
                title TEXT NOT NULL,
                default_expiry_days INTEGER NOT NULL DEFAULT 30,
                default_threshold_days INTEGER NOT NULL DEFAULT 7,
                default_weight_g INTEGER NULL,
                sort_order INTEGER NOT NULL DEFAULT 0
            )",
            "CREATE TABLE IF NOT EXISTS login_attempts (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                ip TEXT NOT NULL,
                attempted_at TEXT NOT NULL
            )",
            'CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip, attempted_at)',
        ];
    }

    /** @return list<string> */
    public function indexStatements(): array
    {
        return [
            'CREATE INDEX IF NOT EXISTS idx_products_status_expiry ON products(status, expiry_date)',
            'CREATE INDEX IF NOT EXISTS idx_products_type ON products(type_id)',
        ];
    }

    public static function productsTableSql(string $table = 'products'): string
    {
        return "CREATE TABLE IF NOT EXISTS {$table} (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            type_id INTEGER NOT NULL REFERENCES types(id),
            weight_g INTEGER NOT NULL CHECK (weight_g > 0),
            expiry_date TEXT NOT NULL,
            threshold_days INTEGER NOT NULL DEFAULT 7,
            status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'used', 'disposed')),
            created_at TEXT NOT NULL DEFAULT (datetime('now')),
            updated_at TEXT NOT NULL DEFAULT (datetime('now')),
            closed_at TEXT NULL,
            expiry_days_at_close INTEGER NULL
        )";
    }

    public function tableColumns(string $table): array
    {
        $columns = [];
        foreach ($this->fetchAll('PRAGMA table_info(' . $this->quoteIdentifier($table) . ')') as $row) {
            $columns[(string) $row['name']] = $row;
        }

        return $columns;
    }

    public function tableExists(string $table): bool
    {
        return (bool) $this->fetchValue(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$table]
        );
    }

    public function userVersion(): int
    {
        return (int) $this->fetchValue('PRAGMA user_version');
    }

    public function setUserVersion(int $version): void
    {
        $this->connector->exec('PRAGMA user_version = ' . $version);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"' . str_replace('"', '""', $identifier) . '"';
    }
}
