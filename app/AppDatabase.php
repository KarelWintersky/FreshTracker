<?php

declare(strict_types=1);

namespace FreshTracker;

use FreshTracker\Database\DatabaseDriver;
use FreshTracker\Database\SQLiteDriver;
use FreshTracker\Database\MySQLDriver;
use FreshTracker\Database\PostgreSQLDriver;
use PDO;
use PDOStatement;
use RuntimeException;

class AppDatabase
{
    private DatabaseDriver $driver;

    public function __construct(array $config)
    {
        $driverName = $config['driver'] ?? 'sqlite';

        $driverClass = match ($driverName) {
            'sqlite'     => SQLiteDriver::class,
            'mysql'      => MySQLDriver::class,
            'postgresql' => PostgreSQLDriver::class,
            default      => null,
        };

        if ($driverClass === null || !class_exists($driverClass)) {
            throw new RuntimeException("Database driver not found: {$driverName}");
        }

        $this->driver = new $driverClass($config);
    }

    public function getDriver(): DatabaseDriver
    {
        return $this->driver;
    }

    public function getPdo(): PDO
    {
        return $this->driver->getPdo();
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        return $this->driver->query($sql, $params);
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->driver->fetchAll($sql, $params);
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        return $this->driver->fetchOne($sql, $params);
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        return $this->driver->fetchValue($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->driver->execute($sql, $params);
    }

    public function insert(string $sql, array $params = []): int
    {
        return $this->driver->insert($sql, $params);
    }

    public function beginTransaction(): bool
    {
        return $this->driver->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->driver->commit();
    }

    public function rollback(): bool
    {
        return $this->driver->rollback();
    }

    public function transaction(callable $callback): mixed
    {
        return $this->driver->transaction($callback);
    }

    // === SQL fragment delegation ===

    public function sqlNow(): string
    {
        return $this->driver->sqlNow();
    }

    public function sqlNowMinusInterval(int $seconds): string
    {
        return $this->driver->sqlNowMinusInterval($seconds);
    }

    public function sqlNowPlusInterval(int $seconds): string
    {
        return $this->driver->sqlNowPlusInterval($seconds);
    }

    public function sqlCoalesceNow(string $column): string
    {
        return $this->driver->sqlCoalesceNow($column);
    }

    public function sqlDaysRemaining(string $dateColumn): string
    {
        return $this->driver->sqlDaysRemaining($dateColumn);
    }

    public function sqlNullsFirst(string $column): string
    {
        return $this->driver->sqlNullsFirst($column);
    }

    // === Statement helper delegation ===

    public function insertIgnore(string $table, array $data): int
    {
        return $this->driver->insertIgnore($table, $data);
    }

    public function upsert(string $table, array $data, string $conflictColumn, array $set): void
    {
        $this->driver->upsert($table, $data, $conflictColumn, $set);
    }

    public function createTables(): void
    {
        $this->driver->createTables();
    }

    public function getDatabaseSize(): int
    {
        return $this->driver->getDatabaseSize();
    }

    public function close(): void
    {
        $this->driver->close();
    }

    public function __destruct()
    {
        $this->close();
    }
}
