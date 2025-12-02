<?php

use FreshTracker\App;

if (!defined("START_TIME")) { define("START_TIME", microtime(true)); }
if (!defined("CONFIG_PATH")) { define("CONFIG_PATH", $_SERVER['APP_CONFIG'] ?? __DIR__ . DIRECTORY_SEPARATOR . 'config' ); };
if (!defined("DATABASE_PATH")) { define("DATABASE_PATH", $_SERVER['APP_DATABASE'] ?? __DIR__ . '/../freshtracker.sqlite' ); };
if (!defined("IS_PRODUCTION")) { define("IS_PRODUCTION", !is_file(__DIR__ . '/../composer.lock')); }

if (IS_PRODUCTION === false) {
    require_once __DIR__ . '/../vendor/autoload.php';
} else {
    require_once __DIR__ . '/freshtracker.phar';
}


$config = [
    'database' => [
        'path' => DATABASE_PATH,
        'options' => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    ],
    'defaults' => [
        'threshold_days' => 7,
        'type' => 'разное'
    ],
    'validation' => [
        'max_weight' => 1000,
        'max_threshold_days' => 365,
        'min_weight' => 0.001
    ]
];
App::$config = \FreshTracker\Config::mergeWithDefaults($config);

$api = new \FreshTracker\API(App::$config);

try {
    $method = $_SERVER['REQUEST_METHOD'];
    $id = $api->getIdFromQuery();

    match ($method) {
        'GET' => $id ? $api->getProduct($id) : $api->getProducts(),
        'POST' => $api->createProduct(),
        'PUT' => $id ? $api->updateProduct($id) : $api->sendJsonError('ID продукта не указан', 400),
        'DELETE' => $id ? $api->deleteProduct($id) : $api->sendJsonError('ID продукта не указан', 400),
        'OPTIONS' => $api->handleCORS(),
        default => $api->sendJsonError('Метод не поддерживается', 405)
    };

} catch (Throwable $e) {

    \FreshTracker\Response::setError($e->getMessage(), $e->getCode());
    $api->sendJsonError($e->getMessage(), $e->getCode());

} finally {

    \FreshTracker\Response::send();

}
