<?php

namespace FreshTracker;

use PDO;

class App
{
    public static PDO $pdo;
    public static array $config;

    public static function init($config = []): void
    {
        App::$config = \FreshTracker\Config::mergeWithDefaults($config);

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