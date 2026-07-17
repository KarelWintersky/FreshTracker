<?php

use Arris\AppRouter;
use FreshTracker\App;
use FreshTracker\Controllers\ConfigController;
use FreshTracker\Controllers\ProductsController;
use FreshTracker\Controllers\ResponseController;

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

App::init([]);

try {
    AppRouter::init(
        logger: null,
        allowEmptyHandlers: true,
    );

    AppRouter::addHandler(ProductsController::class, new ProductsController());
    AppRouter::addHandler(ConfigController::class, new ConfigController());

    AppRouter::group(prefix: '/api', callback: function () {

        AppRouter::get('/config/', [ ConfigController::class, 'get']);

        AppRouter::group(prefix: '/products', callback: function () {

            AppRouter::get('/', [ ProductsController::class, 'getProducts']);
            AppRouter::get('/{id}/', [ ProductsController::class, 'getProduct']);

            AppRouter::post('/', [ ProductsController::class, 'createProduct']);

            AppRouter::put('/{id}/', [ ProductsController::class, 'updateProduct']);
            AppRouter::delete('/{id}/', [ ProductsController::class, 'deleteProduct']);

            AppRouter::options('/', [ ResponseController::class, 'sendCORS']);
        });
    });

    AppRouter::dispatch();

} catch (Throwable $e) {
    ResponseController::setError($e->getMessage(), $e->getCode());
} finally {
    ResponseController::send();
}
