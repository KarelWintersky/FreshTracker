<?php

declare(strict_types=1);

namespace FreshTracker\Database;

use Arris\Database\Connector;
use PDO;
use PDOStatement;

/**
 * Micro-DBAL on top of Arris\Database\Connector.
 *
 * The driver owns SQL dialect fragments (timestamps, day differences, schema)
 * while the actual connection, statement proxying and slow-query stats come
 * from Arris\Database.
 */
abstract class DatabaseDriver
{
    protected Connector $connector;
    protected array $config;

    public function __construct(Connector $connector, array $config)
    {
        $this->connector = $connector;
        $this->config = $config;
        $this->configure();
    }

    abstract public function name(): string;

    /** Driver-level connection tweaks (SQLite PRAGMAs, etc.). */
    protected function configure(): void
    {
    }

    /** Current timestamp expression. */
    abstract public function sqlNow(): string;

    /** Whole days between the date column and today. 0 = expires today. */
    abstract public function sqlDaysRemaining(string $dateColumn): string;

    /** Create the application schema if it does not exist. */
    abstract public function createTables(): void;

    /** @return array<string, array<string, mixed>> column name => metadata row */
    abstract public function tableColumns(string $table): array;

    public function connector(): Connector
    {
        return $this->connector;
    }

    public function pdo(): PDO
    {
        return $this->connector->pdo();
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        $statement = $this->connector->prepare($sql);
        $statement->execute($params);

        return $statement;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $row = $this->query($sql, $params)->fetch(PDO::FETCH_NUM);

        return $row === false ? null : ($row[0] ?? null);
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->query($sql, $params);

        return (int) $this->connector->lastInsertId();
    }

    public function beginTransaction(): bool
    {
        return $this->connector->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->connector->commit();
    }

    public function rollback(): bool
    {
        return $this->connector->rollBack();
    }

    public function transaction(callable $callback): mixed
    {
        return $this->connector->transaction(fn () => $callback($this));
    }
}
