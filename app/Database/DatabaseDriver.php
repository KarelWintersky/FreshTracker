<?php

declare(strict_types=1);

namespace FreshTracker\Database;

use PDO;
use PDOStatement;
use RuntimeException;

abstract class DatabaseDriver
{
    protected ?PDO $pdo = null;
    protected array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
        $this->pdo = $this->connect();
    }

    abstract protected function connect(): PDO;

    // === SQL fragment methods ===

    /** Current timestamp expression */
    abstract public function sqlNow(): string;

    /** Now minus N seconds */
    abstract public function sqlNowMinusInterval(int $seconds): string;

    /** Now plus N seconds */
    abstract public function sqlNowPlusInterval(int $seconds): string;

    /** COALESCE(col, now) for initial timestamp */
    abstract public function sqlCoalesceNow(string $column): string;

    /** Days remaining calculation from expiry_date column */
    abstract public function sqlDaysRemaining(string $dateColumn): string;

    /** NULLS FIRST for ORDER BY */
    abstract public function sqlNullsFirst(string $column): string;

    // === Statement helper methods ===

    /** INSERT OR IGNORE equivalent */
    abstract public function insertIgnore(string $table, array $data): int;

    /** UPSERT: INSERT ... ON CONFLICT DO UPDATE */
    abstract public function upsert(string $table, array $data, string $conflictColumn, array $set): void;

    /** Schema: create tables */
    abstract public function createTables(): void;

    // === Generic PDO methods ===

    abstract public function getDatabaseSize(): int;

    public function getPdo(): PDO
    {
        if ($this->pdo === null) {
            throw new RuntimeException('Database connection is closed');
        }
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): PDOStatement
    {
        if ($this->pdo === null) {
            throw new RuntimeException('Database connection is closed');
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll(PDO::FETCH_ASSOC);
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    public function fetchValue(string $sql, array $params = []): mixed
    {
        $row = $this->query($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row[0] ?? null;
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    public function insert(string $sql, array $params = []): int
    {
        $this->query($sql, $params);
        return (int) $this->getPdo()->lastInsertId();
    }

    public function beginTransaction(): bool
    {
        return $this->getPdo()->beginTransaction();
    }

    public function commit(): bool
    {
        return $this->getPdo()->commit();
    }

    public function rollback(): bool
    {
        return $this->getPdo()->rollBack();
    }

    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback();
            $this->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function close(): void
    {
        $this->pdo = null;
    }

    public function __destruct()
    {
        $this->close();
    }
}
