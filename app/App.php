<?php

namespace FreshTracker;

use Arris\Database\Connector;
use PDO;

class App extends \Arris\App
{
    public static PDO $pdo;
    public static array $config = [];

    protected function getDefaultConfig(): array
    {
        return AppConfig::getDefaultConfig();
    }

    public static function init($config = []): void
    {
        App::factory([
            "?" . dirname(__DIR__) . '/freshtracker.yml'
        ]);

        $db = new Database($config);
        $db->initializeDatabase();

        App::$pdo = $db->getConnection();
    }

    public static function getIdFromQuery(): ?int
    {
        $id = $_GET['id'] ?? null;

        if ($id && is_numeric($id)) {
            return (int)$id;
        }

        return null;
    }

}