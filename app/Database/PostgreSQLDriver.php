<?php

declare(strict_types=1);

namespace FreshTracker\Database;

final class PostgreSQLDriver extends DatabaseDriver
{
    public function name(): string
    {
        return 'postgresql';
    }

    protected function configure(): void
    {
        $this->connector->exec("SET timezone = 'UTC'");
    }

    public function sqlNow(): string
    {
        return 'NOW()';
    }

    public function sqlDaysRemaining(string $dateColumn): string
    {
        return "({$dateColumn}::date - CURRENT_DATE)";
    }

    public function createTables(): void
    {
        foreach ($this->schemaStatements() as $sql) {
            $this->connector->exec($sql);
        }
    }

    /** @return list<string> */
    private function schemaStatements(): array
    {
        return [
            'CREATE TABLE IF NOT EXISTS types (
                id SERIAL PRIMARY KEY,
                slug VARCHAR(64) NOT NULL UNIQUE,
                title VARCHAR(128) NOT NULL,
                default_expiry_days INTEGER NOT NULL DEFAULT 30,
                default_threshold_days INTEGER NOT NULL DEFAULT 7,
                default_weight_g INTEGER NULL,
                sort_order INTEGER NOT NULL DEFAULT 0
            )',
            'CREATE TABLE IF NOT EXISTS products (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                type_id INTEGER NOT NULL REFERENCES types(id),
                weight_g INTEGER NOT NULL CHECK (weight_g > 0),
                expiry_date DATE NOT NULL,
                threshold_days INTEGER NOT NULL DEFAULT 7,
                status VARCHAR(16) NOT NULL DEFAULT \'active\',
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                closed_at TIMESTAMP NULL,
                expiry_days_at_close INTEGER NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_products_status_expiry ON products(status, expiry_date)',
            'CREATE INDEX IF NOT EXISTS idx_products_type ON products(type_id)',
            'CREATE TABLE IF NOT EXISTS login_attempts (
                id SERIAL PRIMARY KEY,
                ip VARCHAR(64) NOT NULL,
                attempted_at TIMESTAMP NOT NULL
            )',
            'CREATE INDEX IF NOT EXISTS idx_login_attempts_ip ON login_attempts(ip, attempted_at)',
        ];
    }

    public function tableColumns(string $table): array
    {
        $columns = [];
        $rows = $this->fetchAll(
            'SELECT column_name AS name FROM information_schema.columns
             WHERE table_schema = current_schema() AND table_name = ?',
            [$table]
        );
        foreach ($rows as $row) {
            $columns[(string) $row['name']] = $row;
        }

        return $columns;
    }
}
