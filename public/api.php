<?php

declare(strict_types=1);

use FreshTracker\Access\ClientIp;
use FreshTracker\Bootstrap\App;
use FreshTracker\Http\HttpException;
use FreshTracker\Http\JsonGuard;
use FreshTracker\Http\Response;
use FreshTracker\Http\Router;

$root = dirname(__DIR__);
$autoload = $root . '/vendor/autoload.php';
$phar = $root . '/freshtracker.phar';

if (is_file($autoload)) {
    // Source checkout: never let a stale PHAR shadow the live code.
    require $autoload;
} elseif (is_file($phar)) {
    // Minimal deployment: public/ + freshtracker.phar + config + database.
    require $phar;
} else {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Application code is not installed (vendor/ or freshtracker.phar)']);
    exit;
}

try {
    App::boot($root);
} catch (Throwable $e) {
    error_log('FreshTracker bootstrap failed: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'message' => 'Application bootstrap failed']);
    exit;
}

$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$path = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');

try {
    $ip = ClientIp::resolve((array) App::config('security.trusted_proxies', []));
    $context = App::policy()->context($ip, App::session());
    App::setContext($context, $ip);

    if (str_starts_with($path, '/api/auth/')) {
        JsonGuard::assertSameOrigin();
    } else {
        if (!$context->canView) {
            throw new HttpException('Доступ закрыт', 403);
        }

        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            JsonGuard::assertSameOrigin();

            if (!$context->canEdit) {
                throw new HttpException('Только чтение', 403);
            }
        }
    }

    Router::dispatch();
} catch (HttpException $e) {
    Response::error($e->getMessage(), $e->status);
} catch (\Arris\Exceptions\AppRouterNotFoundException $e) {
    Response::error('Не найдено', 404);
} catch (\Arris\Exceptions\AppRouterMethodNotAllowedException $e) {
    Response::error('Метод не поддерживается', 405);
} catch (Throwable $e) {
    App::logger()->error('Unhandled API error', ['exception' => $e]);
    Response::error(App::isDebug() ? $e->getMessage() : 'Внутренняя ошибка сервера', 500);
}
