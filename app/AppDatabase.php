<?php

declare(strict_types=1);

namespace FreshTracker;

use Arris\Database\Config as ArrisDatabaseConfig;
use Arris\Database\Connector;
use FreshTracker\Database\DatabaseDriver;
use FreshTracker\Database\MySQLDriver;
use FreshTracker\Database\PostgreSQLDriver;
use FreshTracker\Database\SQLiteDriver;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Facade over a database driver. Keeps the dialect-specific SQL fragments
 * out of controllers and repositories.
 */
final class AppDatabase
{
    private DatabaseDriver $driver;
    private ?string $path;

    public function __construct(array $config, ?LoggerInterface $logger = null)
    {
        $driverName = strtolower((string) ($config['driver'] ?? 'sqlite'));
        $driverName = $driverName === 'pgsql' ? 'postgresql' : $driverName;

        $path = $config['path'] ?? $config['database'] ?? null;
        $this->path = is_string($path) ? $path : null;

        if ($driverName === 'sqlite' && is_string($path) && $path !== ':memory:') {
            $directory = dirname($path);
            if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
                throw new RuntimeException("Cannot create database directory: {$directory}");
            }
        }

        $connector = new Connector(new ArrisDatabaseConfig([
            'driver'                => $driverName === 'postgresql' ? 'pgsql' : $driverName,
            'database'              => $path,
            'hostname'              => $config['host'] ?? $config['hostname'] ?? '127.0.0.1',
            'port'                  => $config['port'] ?? null,
            'username'              => $config['user'] ?? $config['username'] ?? 'root',
            'password'              => $config['password'] ?? '',
            'slow_query_threshold'  => $config['slow_query_threshold'] ?? 1.0,
        ], $logger));

        $this->driver = match ($driverName) {
            'sqlite'        => new SQLiteDriver($connector, $config),
            'mysql'         => new MySQLDriver($connector, $config),
            'postgresql'    => new PostgreSQLDriver($connector, $config),
            default         => throw new RuntimeException("Unsupported database driver: {$driverName}"),
        };
    }

    public function getDriver(): DatabaseDriver
    {
        return $this->driver;
    }

    public function driverName(): string
    {
        return $this->driver->name();
    }

    public function path(): ?string
    {
        return $this->path;
    }

    public function getPdo(): \PDO
    {
        return $this->driver->pdo();
    }

    public function query(string $sql, array $params = []): \PDOStatement
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

    public function sqlNow(): string
    {
        return $this->driver->sqlNow();
    }

    public function sqlDaysRemaining(string $dateColumn): string
    {
        return $this->driver->sqlDaysRemaining($dateColumn);
    }

    public function createTables(): void
    {
        $this->driver->createTables();
    }
}
