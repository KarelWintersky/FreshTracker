<?php

declare(strict_types=1);

namespace FreshTracker\Database;

use PDO;
use RuntimeException;

class PostgreSQLDriver extends DatabaseDriver
{
    protected function connect(): PDO
    {
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 5432;
        $dbname = $this->config['database'] ?? $this->config['name'] ?? 'freshtracker';
        $username = $this->config['username'] ?? $this->config['user'] ?? 'postgres';
        $password = $this->config['password'] ?? '';

        $dsn = "pgsql:host={$host};port={$port};dbname={$dbname}";

        try {
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES    => false,
                PDO::ATTR_TIMEOUT             => 5,
            ]);

            $pdo->exec("SET timezone = 'UTC'");

            return $pdo;
        } catch (\PDOException $e) {
            throw new RuntimeException("PostgreSQL connection failed: {$e->getMessage()}", 0, $e);
        }
    }

    // === SQL fragments ===

    public function sqlNow(): string
    {
        return "NOW()";
    }

    public function sqlNowMinusInterval(int $seconds): string
    {
        return "NOW() - INTERVAL '{$seconds} seconds'";
    }

    public function sqlNowPlusInterval(int $seconds): string
    {
        return "NOW() + INTERVAL '{$seconds} seconds'";
    }

    public function sqlCoalesceNow(string $column): string
    {
        return "COALESCE({$column}, NOW())";
    }

    public function sqlDaysRemaining(string $dateColumn): string
    {
        return "EXTRACT(DAY FROM {$dateColumn}::timestamp - NOW())";
    }

    public function sqlNullsFirst(string $column): string
    {
        return "{$column} NULLS FIRST";
    }

    // === Statement helpers ===

    public function insertIgnore(string $table, array $data): int
    {
        $cols = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        return $this->insert(
            "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders}) ON CONFLICT DO NOTHING",
            array_values($data)
        );
    }

    public function upsert(string $table, array $data, string $conflictColumn, array $set): void
    {
        $cols = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));

        $assignments = [];
        $params = array_values($data);

        foreach ($set as $col => $val) {
            if ($val === null) {
                $assignments[] = "{$col} = NULL";
            } elseif ($val === '=excluded') {
                $assignments[] = "{$col} = EXCLUDED.{$col}";
            } elseif ($val === '=now') {
                $assignments[] = "{$col} = NOW()";
            } elseif (is_string($val) && str_starts_with($val, '=expr:')) {
                $assignments[] = "{$col} = " . substr($val, 6);
            } else {
                $assignments[] = "{$col} = ?";
                $params[] = $val;
            }
        }

        $sql = "INSERT INTO {$table} ({$cols}) VALUES ({$placeholders})"
            . " ON CONFLICT({$conflictColumn}) DO UPDATE SET "
            . implode(', ', $assignments);

        $this->execute($sql, $params);
    }

    // === Schema ===

    public function createTables(): void
    {
        $this->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS products (
                id SERIAL PRIMARY KEY,
                name VARCHAR(255) NOT NULL,
                weight DOUBLE PRECISION NOT NULL,
                expiry_date DATE NOT NULL,
                type VARCHAR(50) NOT NULL,
                threshold_days INTEGER DEFAULT 7,
                is_deleted BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                deleted_at TIMESTAMP NULL
            )
        ");

        $this->getPdo()->exec("CREATE INDEX IF NOT EXISTS idx_expiry_date ON products(expiry_date)");
        $this->getPdo()->exec("CREATE INDEX IF NOT EXISTS idx_type ON products(type)");
        $this->getPdo()->exec("CREATE INDEX IF NOT EXISTS idx_is_deleted ON products(is_deleted)");
    }

    // === PostgreSQL-specific ===

    public function getDatabaseSize(): int
    {
        $result = $this->fetchValue(
            "SELECT pg_database_size(current_database())"
        );
        return (int) ($result ?? 0);
    }

    public function vacuum(): void
    {
        $this->getPdo()->exec('VACUUM');
    }
}
