<?php

declare(strict_types=1);

namespace FreshTracker\Database;

use PDO;
use RuntimeException;

class MySQLDriver extends DatabaseDriver
{
    protected function connect(): PDO
    {
        $host = $this->config['host'] ?? '127.0.0.1';
        $port = $this->config['port'] ?? 3306;
        $dbname = $this->config['database'] ?? $this->config['name'] ?? 'freshtracker';
        $username = $this->config['username'] ?? $this->config['user'] ?? 'root';
        $password = $this->config['password'] ?? '';

        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset=utf8mb4";

        try {
            $pdo = new PDO($dsn, $username, $password, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE  => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES    => false,
                PDO::ATTR_TIMEOUT             => 5,
            ]);

            return $pdo;
        } catch (\PDOException $e) {
            throw new RuntimeException("MySQL connection failed: {$e->getMessage()}", 0, $e);
        }
    }

    // === SQL fragments ===

    public function sqlNow(): string
    {
        return "NOW()";
    }

    public function sqlNowMinusInterval(int $seconds): string
    {
        $interval = $this->secondsToInterval($seconds);
        return "NOW() - INTERVAL {$interval}";
    }

    public function sqlNowPlusInterval(int $seconds): string
    {
        $interval = $this->secondsToInterval($seconds);
        return "NOW() + INTERVAL {$interval}";
    }

    public function sqlCoalesceNow(string $column): string
    {
        return "COALESCE({$column}, NOW())";
    }

    public function sqlDaysRemaining(string $dateColumn): string
    {
        return "DATEDIFF({$dateColumn}, NOW())";
    }

    public function sqlNullsFirst(string $column): string
    {
        return "{$column} IS NULL DESC, {$column} ASC";
    }

    // === Statement helpers ===

    public function insertIgnore(string $table, array $data): int
    {
        $cols = implode(', ', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        return $this->insert(
            "INSERT IGNORE INTO {$table} ({$cols}) VALUES ({$placeholders})",
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
                $assignments[] = "{$col} = VALUES({$col})";
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
            . " ON DUPLICATE KEY UPDATE "
            . implode(', ', $assignments);

        $this->execute($sql, $params);
    }

    // === Schema ===

    public function createTables(): void
    {
        $this->getPdo()->exec("
            CREATE TABLE IF NOT EXISTS products (
                id INTEGER PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                weight DOUBLE NOT NULL,
                expiry_date DATE NOT NULL,
                type VARCHAR(50) NOT NULL,
                threshold_days INT DEFAULT 7,
                is_deleted BOOLEAN DEFAULT 0,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                deleted_at DATETIME NULL,
                INDEX idx_expiry_date (expiry_date),
                INDEX idx_type (type),
                INDEX idx_is_deleted (is_deleted)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    // === MySQL-specific ===

    public function getDatabaseSize(): int
    {
        $result = $this->fetchValue(
            "SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()"
        );
        return (int) ($result ?? 0);
    }

    private function secondsToInterval(int $seconds): string
    {
        $days = intdiv($seconds, 86400);
        $hours = intdiv($seconds % 86400, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        $parts = [];
        if ($days > 0) $parts[] = "{$days} DAY";
        if ($hours > 0) $parts[] = "{$hours} HOUR";
        if ($minutes > 0) $parts[] = "{$minutes} MINUTE";
        if ($secs > 0 || empty($parts)) $parts[] = "{$secs} SECOND";

        return implode(' ', $parts);
    }
}
