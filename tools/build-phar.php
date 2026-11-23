<?php

declare(strict_types=1);

/**
 * Build freshtracker.phar from app/ + vendor/ without external tools.
 *
 * Usage: php -d phar.readonly=0 tools/build-phar.php
 */

if ((int) ini_get('phar.readonly') === 1) {
    fwrite(STDERR, "phar.readonly is enabled; run with -d phar.readonly=0\n");
    exit(1);
}

$root = dirname(__DIR__);
$output = $root . '/freshtracker.phar';

if (is_file($output) && !unlink($output)) {
    fwrite(STDERR, "Cannot remove the previous PHAR: {$output}\n");
    exit(1);
}

$phar = new Phar($output, 0, 'freshtracker.phar');
$phar->startBuffering();

$addDirectory = static function (string $directory, string $prefix) use ($phar): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $file) {
        if (!$file->isFile()) {
            continue;
        }

        $path = $file->getPathname();
        $relative = substr($path, strlen($directory) + 1);
        $phar->addFile($path, $prefix . '/' . $relative);
    }
};

$addDirectory($root . '/app', 'app');
$addDirectory($root . '/vendor', 'vendor');

$stub = <<<'STUB'
<?php
Phar::mapPhar('freshtracker.phar');
require 'phar://freshtracker.phar/vendor/autoload.php';
__HALT_COMPILER();
STUB;

$phar->setStub($stub);
$phar->compressFiles(Phar::GZ);
$phar->stopBuffering();

printf("Built %s (%d bytes)\n", $output, filesize($output));
