<?php

declare(strict_types=1);

namespace FreshTracker\Http;

use Arris\AppRouter;
use FreshTracker\Bootstrap\App;
use FreshTracker\Controllers\AuthController;
use FreshTracker\Controllers\ConfigController;
use FreshTracker\Controllers\ProductsController;
use FreshTracker\Controllers\StatsController;
use FreshTracker\Controllers\TypesController;

final class Router
{
    public static function dispatch(): void
    {
        AppRouter::init(logger: App::logger(), allowEmptyHandlers: true);

        AppRouter::addHandler(AuthController::class, new AuthController());
        AppRouter::addHandler(ConfigController::class, new ConfigController());
        AppRouter::addHandler(TypesController::class, new TypesController());
        AppRouter::addHandler(ProductsController::class, new ProductsController());
        AppRouter::addHandler(StatsController::class, new StatsController());

        AppRouter::group(prefix: '/api', callback: static function (): void {
            AppRouter::get('/config', [ConfigController::class, 'get']);
            AppRouter::get('/config/', [ConfigController::class, 'get']);

            AppRouter::get('/types', [TypesController::class, 'index']);
            AppRouter::get('/types/', [TypesController::class, 'index']);

            AppRouter::get('/stats', [StatsController::class, 'index']);
            AppRouter::get('/stats/', [StatsController::class, 'index']);

            AppRouter::group(prefix: '/products', callback: static function (): void {
                AppRouter::get('', [ProductsController::class, 'index']);
                AppRouter::get('/', [ProductsController::class, 'index']);
                AppRouter::post('', [ProductsController::class, 'store']);
                AppRouter::post('/', [ProductsController::class, 'store']);

                AppRouter::get('/{id}', [ProductsController::class, 'show']);
                AppRouter::get('/{id}/', [ProductsController::class, 'show']);
                AppRouter::put('/{id}', [ProductsController::class, 'update']);
                AppRouter::put('/{id}/', [ProductsController::class, 'update']);
                AppRouter::delete('/{id}', [ProductsController::class, 'destroy']);
                AppRouter::delete('/{id}/', [ProductsController::class, 'destroy']);

                AppRouter::post('/{id}/use', [ProductsController::class, 'markUsed']);
                AppRouter::post('/{id}/use/', [ProductsController::class, 'markUsed']);
                AppRouter::post('/{id}/dispose', [ProductsController::class, 'dispose']);
                AppRouter::post('/{id}/dispose/', [ProductsController::class, 'dispose']);
                AppRouter::post('/{id}/restore', [ProductsController::class, 'restore']);
                AppRouter::post('/{id}/restore/', [ProductsController::class, 'restore']);
            });

            AppRouter::post('/auth/login', [AuthController::class, 'login']);
            AppRouter::post('/auth/login/', [AuthController::class, 'login']);
            AppRouter::post('/auth/logout', [AuthController::class, 'logout']);
            AppRouter::post('/auth/logout/', [AuthController::class, 'logout']);
        });

        AppRouter::dispatch();
    }
}
