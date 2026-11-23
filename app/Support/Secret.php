<?php

declare(strict_types=1);

namespace FreshTracker\Support;

use RuntimeException;

final class Secret
{
    public static function loadOrCreate(string $path): string
    {
        $fromEnv = getenv('APP_SECRET');
        if (is_string($fromEnv) && $fromEnv !== '') {
            return $fromEnv;
        }

        if (is_file($path)) {
            $stored = trim((string) file_get_contents($path));
            if ($stored !== '') {
                return $stored;
            }
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException("Cannot create secret directory: {$directory}");
        }

        $secret = bin2hex(random_bytes(32));
        if (@file_put_contents($path, $secret, LOCK_EX) === false) {
            throw new RuntimeException("Cannot write secret file: {$path}");
        }
        @chmod($path, 0600);

        return $secret;
    }
}
