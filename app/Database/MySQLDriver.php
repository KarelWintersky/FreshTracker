<?php

declare(strict_types=1);

namespace FreshTracker\Database;

final class MySQLDriver extends DatabaseDriver
{
    public function name(): string
    {
        return 'mysql';
    }

    public function sqlNow(): string
    {
        return 'NOW()';
    }

    public function sqlDaysRemaining(string $dateColumn): string
    {
        return "DATEDIFF({$dateColumn}, CURDATE())";
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
            "CREATE TABLE IF NOT EXISTS types (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                slug VARCHAR(64) NOT NULL UNIQUE,
                title VARCHAR(128) NOT NULL,
                default_expiry_days INT NOT NULL DEFAULT 30,
                default_threshold_days INT NOT NULL DEFAULT 7,
                default_weight_g INT NULL,
                sort_order INT NOT NULL DEFAULT 0
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS products (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                name VARCHAR(255) NOT NULL,
                type_id INT UNSIGNED NOT NULL,
                weight_g INT NOT NULL,
                expiry_date DATE NOT NULL,
                threshold_days INT NOT NULL DEFAULT 7,
                status VARCHAR(16) NOT NULL DEFAULT 'active',
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                closed_at DATETIME NULL,
                expiry_days_at_close INT NULL,
                INDEX idx_products_status_expiry (status, expiry_date),
                INDEX idx_products_type (type_id),
                CONSTRAINT fk_products_type FOREIGN KEY (type_id) REFERENCES types(id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
            "CREATE TABLE IF NOT EXISTS login_attempts (
                id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT,
                ip VARCHAR(64) NOT NULL,
                attempted_at DATETIME NOT NULL,
                INDEX idx_login_attempts_ip (ip, attempted_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
        ];
    }

    public function tableColumns(string $table): array
    {
        $columns = [];
        $rows = $this->fetchAll(
            'SELECT column_name AS name FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = ?',
            [$table]
        );
        foreach ($rows as $row) {
            $columns[(string) $row['name']] = $row;
        }

        return $columns;
    }
}
