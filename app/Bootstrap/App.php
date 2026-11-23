<?php

declare(strict_types=1);

namespace FreshTracker\Bootstrap;

use Arris\AppConfig;
use Arris\AppLogger;
use FreshTracker\Access\AccessContext;
use FreshTracker\Access\AccessPolicy;
use FreshTracker\Access\EditSession;
use FreshTracker\Access\Throttle;
use FreshTracker\AppDatabase;
use FreshTracker\Config\Defaults;
use FreshTracker\Config\Version;
use FreshTracker\Migrations\Migrator;
use FreshTracker\Support\Secret;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;

final class App
{
    private static ?self $instance = null;
    private static ?AccessContext $context = null;
    private static string $clientIp = '127.0.0.1';

    public readonly AppConfig $config;
    public readonly LoggerInterface $logger;
    public readonly AppDatabase $db;
    public readonly AccessPolicy $policy;
    public readonly EditSession $session;
    public readonly Throttle $throttle;
    public readonly string $root;

    private function __construct(string $root)
    {
        $this->root = $root;

        $configFile = getenv('APP_CONFIG');
        $configFile = is_string($configFile) && $configFile !== ''
            ? $configFile
            : $root . '/config.yaml';

        $files = ['?' . $configFile];
        $legacy = $root . '/freshtracker.yml';

        // Legacy config is used only when no explicit config file is present.
        if ($configFile === $root . '/config.yaml' && !is_file($configFile) && is_file($legacy)) {
            $files[] = '?' . $legacy;
        }

        $this->config = new AppConfig($files, Defaults::all());

        date_default_timezone_set((string) $this->config->get('app.timezone', 'UTC'));

        $this->logger = $this->makeLogger();

        $this->db = new AppDatabase($this->databaseConfig(), $this->logger);
        (new Migrator($this->db))->ensure();

        $secret = (string) $this->config->get('security.secret', '');
        if ($secret === '') {
            $secret = Secret::loadOrCreate($root . '/var/secret');
        }

        $this->session = new EditSession($secret);
        $this->policy = AccessPolicy::fromConfig((array) $this->config->get('access', []), $this->logger);
        $this->throttle = new Throttle($this->db);
    }

    public static function boot(string $root): self
    {
        return self::$instance ??= new self($root);
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            throw new RuntimeException('Application is not booted');
        }

        return self::$instance;
    }

    public static function config(string $key = '', mixed $default = null): mixed
    {
        $config = self::get()->config;

        return $key === '' ? $config->all() : $config->get($key, $default);
    }

    public static function db(): AppDatabase
    {
        return self::get()->db;
    }

    public static function logger(): LoggerInterface
    {
        return self::get()->logger;
    }

    public static function policy(): AccessPolicy
    {
        return self::get()->policy;
    }

    public static function session(): EditSession
    {
        return self::get()->session;
    }

    public static function throttle(): Throttle
    {
        return self::get()->throttle;
    }

    public static function root(): string
    {
        return self::get()->root;
    }

    public static function version(): string
    {
        return Version::VERSION;
    }

    public static function isDebug(): bool
    {
        return (bool) self::config('app.debug', false);
    }

    public static function setContext(AccessContext $context, string $clientIp): void
    {
        self::$context = $context;
        self::$clientIp = $clientIp;
    }

    public static function context(): AccessContext
    {
        return self::$context ?? AccessContext::denied();
    }

    public static function clientIp(): string
    {
        return self::$clientIp;
    }

    private function databaseConfig(): array
    {
        $config = (array) $this->config->get('database', []);

        $path = (string) ($config['path'] ?? $config['database'] ?? 'freshtracker.sqlite');
        $config['path'] = self::absolutePath($path, $this->root);

        $fromEnv = getenv('APP_DATABASE');
        if (is_string($fromEnv) && $fromEnv !== '') {
            $config['path'] = $fromEnv;
        }

        return $config;
    }

    private function makeLogger(): LoggerInterface
    {
        $path = self::absolutePath(
            (string) $this->config->get('logging.path', 'logs'),
            $this->root
        );

        $level = match (strtolower((string) $this->config->get('logging.level', 'info'))) {
            'debug'     => AppLogger::DEBUG,
            'notice'    => AppLogger::NOTICE,
            'warning'   => AppLogger::WARNING,
            'error'     => AppLogger::ERROR,
            default     => AppLogger::INFO,
        };

        try {
            if (!is_dir($path) && !@mkdir($path, 0775, true) && !is_dir($path)) {
                return new NullLogger();
            }

            AppLogger::init('freshtracker', '', [
                'default_logfile_path'  => $path,
                'default_log_level'     => $level,
            ]);
            AppLogger::addScopeLevel(scope: 'app', target: 'freshtracker.log', log_level: $level);

            return AppLogger::scope('app');
        } catch (\Throwable) {
            return new NullLogger();
        }
    }

    private static function absolutePath(string $path, string $root): string
    {
        if ($path === '' || str_starts_with($path, '/') || str_starts_with($path, ':')) {
            return $path;
        }

        if (preg_match('~^[A-Za-z]:[\\\\/]~', $path) === 1) {
            return $path;
        }

        return rtrim($root, '/') . '/' . $path;
    }
}
