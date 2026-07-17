<?php

use Arris\AppRouter;
use FreshTracker\App;
use FreshTracker\Products;
use FreshTracker\Response;

if (!defined("PATH_INSTALL")) { define("PATH_INSTALL", dirname(__DIR__)); }
if (!defined("CONFIG_PATH")) { define("CONFIG_PATH", $_SERVER['APP_CONFIG'] ?? __DIR__ . '/../freshtracker.yml' ); };
if (!defined("DATABASE_PATH")) { define("DATABASE_PATH", $_SERVER['APP_DATABASE'] ?? __DIR__ . '/../freshtracker.sqlite' ); };

if (!defined("START_TIME")) { define("START_TIME", microtime(true)); }
if (!defined("PHAR_PATH")) { define("PHAR_PATH", __DIR__ . '/../freshtracker.phar'); }

if (is_file(PHAR_PATH)) {
    require_once PHAR_PATH;
} else {
    require_once __DIR__ . '/../vendor/autoload.php';
}

/*$config = [
    'database'  => [
        'path'      => DATABASE_PATH,
        'options'   => [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    ],
    'defaults' => [
        'threshold_days'    => 7,
        'type'              => 'разное'
    ],
    'validation' => [
        'max_weight' => 1000,
        'max_threshold_days' => 365,
        'min_weight' => 0.001
    ]
];*/

App::init([]);

try {
    AppRouter::init(
        logger: null,
        allowEmptyHandlers: true,
    );

    AppRouter::addHandler(Products::class, new Products());

    AppRouter::group(prefix: '/api', callback: function () {

        AppRouter::group(prefix: '/products', callback: function () {

            AppRouter::get('/', [ Products::class, 'getProducts']);
            AppRouter::get('/{id}/', [ Products::class, 'getProduct']);

            AppRouter::post('/', [ Products::class, 'createProduct']);

            AppRouter::put('/{id}/', [ Products::class, 'updateProduct']);
            AppRouter::delete('/{id}/', [ Products::class, 'deleteProduct']);

            AppRouter::options('/', [ Response::class, 'sendCORS']);
        });
    });

    AppRouter::dispatch();

} catch (Throwable $e) {
    Response::setError($e->getMessage(), $e->getCode());
} finally {
    Response::send();
}
