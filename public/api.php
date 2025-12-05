<?php

use Arris\AppRouter;
use Arris\Exceptions\AppRouterNotFoundException;
use FreshTracker\App;
use FreshTracker\Products;

if (!defined("CONFIG_PATH")) { define("CONFIG_PATH", $_SERVER['APP_CONFIG'] ?? __DIR__ . '/../freshtracker.yml' ); };
if (!defined("DATABASE_PATH")) { define("DATABASE_PATH", $_SERVER['APP_DATABASE'] ?? __DIR__ . '/../freshtracker.sqlite' ); };

if (!defined("START_TIME")) { define("START_TIME", microtime(true)); }
if (!defined("PHAR_PATH")) { define("PHAR_PATH", __DIR__ . '/../freshtracker.phar'); }

if (is_file(PHAR_PATH)) {
    require_once PHAR_PATH;
} else {
    require_once __DIR__ . '/../vendor/autoload.php';
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

    AppRouter::init(
        logger: null,
        allowEmptyHandlers: true,
    );

    AppRouter::addHandler(Products::class, new Products());

    // Определяем маршруты для продуктов
   /* AppRouter::group(prefix: '/products', callback: function () {
        AppRouter::get('/', [Products::class, 'getProducts'], 'products.index');
        AppRouter::get('/{id}/', [Products::class, 'getProduct'], 'products.show');

        AppRouter::post('/', [Products::class, 'createProduct'], 'products.store');
        AppRouter::put('/{id}/', [Products::class, 'updateProduct'], 'products.update');

        AppRouter::delete('/{id}/', [Products::class, 'deleteProduct'], 'products.destroy');

        // AppRouter::addRoute('OPTIONS', '/', [Products::class, 'options'], 'products.options');
    });*/

    AppRouter::group(prefix: '/api', callback: function () {
        AppRouter::get('/', [ Products::class, 'getProducts']);
        AppRouter::get('/{id}/', [ Products::class, 'getProduct']);

        AppRouter::post('/', [ Products::class, 'createProduct']);

        AppRouter::put('/{id}/', [ Products::class, 'updateProduct']);
        AppRouter::delete('/{id}/', [ Products::class, 'deleteProduct']);

        AppRouter::options('/', [ \FreshTracker\Response::class, 'sendCORS ']);
    });

    AppRouter::dispatch();

    /*match ($method) {
        'GET' => $id ? $api->getProduct($id) : $api->getProducts(),
        'POST' => $api->createProduct(),
        'PUT' => $id ? $api->updateProduct($id) : $api->sendJsonError('ID продукта не указан', 400),
        'DELETE' => $id ? $api->deleteProduct($id) : $api->sendJsonError('ID продукта не указан', 400),
        'OPTIONS' => $api->handleCORS(),
        default => $api->sendJsonError('Метод не поддерживается', 405)
    };*/

} catch (Throwable $e) {

    \FreshTracker\Response::setError($e->getMessage(), $e->getCode());
    $api->sendJsonError($e->getMessage(), $e->getCode());

} finally {

    \FreshTracker\Response::send();

}
