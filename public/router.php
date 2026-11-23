<?php

/**
 * Development router for `php -S`.
 *   php -S 127.0.0.1:8080 -t public public/router.php
 */

if (PHP_SAPI !== 'cli-server') {
    http_response_code(404);
    exit;
}

if (str_starts_with((string) ($_SERVER['REQUEST_URI'] ?? '/'), '/api')) {
    require __DIR__ . '/api.php';
    return true;
}

return false;
