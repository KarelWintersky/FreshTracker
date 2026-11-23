<?php

declare(strict_types=1);

namespace FreshTracker\Controllers;

use FreshTracker\Bootstrap\App;
use FreshTracker\Http\HttpException;
use FreshTracker\Http\Input;
use FreshTracker\Http\Response;

final class AuthController
{
    public function login(): never
    {
        $data = Input::json();
        $scope = (string) ($data['scope'] ?? '');
        $password = (string) ($data['password'] ?? '');
        $context = App::context();
        $ip = App::clientIp();

        if (!in_array($scope, ['local', 'public'], true)) {
            throw new HttpException('Неизвестная область доступа', 422);
        }

        if ($context->group !== $scope) {
            throw new HttpException('Пароль для этой области не требуется', 400);
        }

        if (!App::policy()->canRequestPassword($scope)) {
            throw new HttpException('Пароль не настроен', 400);
        }

        if (App::throttle()->tooMany($ip)) {
            throw new HttpException('Слишком много попыток, попробуйте позже', 429);
        }

        if (!App::policy()->verifyPassword($scope, $password)) {
            App::throttle()->hit($ip);
            throw new HttpException('Неверный пароль', 401);
        }

        App::session()->grant($scope);
        App::throttle()->clear($ip);

        Response::ok(['can_edit' => true]);
    }

    public function logout(): never
    {
        App::session()->revoke();

        Response::ok(['can_edit' => false]);
    }
}
