<?php

namespace FreshTracker;

use PDO;
use PDOException;

class Database
{
    private array $config;

    public \PDO $pdo;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * @throws PDOException // 'Ошибка инициализации базы данных: ' . $e->getMessage(), 500
     */
    public function initializeDatabase()
    {
        $dbConfig = $this->config['database'];
        $this->pdo = new PDO('sqlite:' . $dbConfig['path'], options: $dbConfig['options']);
        $this->createTables();
    }

    public function getConnection():PDO
    {
        return $this->pdo;
    }

    private function createTables(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS products (
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
            )
        ");

        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_expiry_date ON products(expiry_date)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_type ON products(type)");
        $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_is_deleted ON products(is_deleted)");
    }

}