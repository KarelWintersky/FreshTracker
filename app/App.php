<?php

namespace FreshTracker;

class App extends \Arris\App
{
    public static AppDatabase $db;
    public static array $config = [];

    private static string $accessLevel = 'admin';

    public static function setAccessLevel(string $level): void { self::$accessLevel = $level; }
    public static function getAccessLevel(): string { return self::$accessLevel; }

    protected function getDefaultConfig(): array
    {
        return AppConfig::getDefaultConfig();
    }

    public static function init($config = []): void
    {
        $configFile = defined('CONFIG_PATH') ? CONFIG_PATH : dirname(__DIR__) . '/freshtracker.yml';

        App::factory([
            "?" . $configFile
        ]);

        $dbConfig = App::config('database', null, []);
        App::$db = new AppDatabase($dbConfig);
        App::$db->createTables();
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