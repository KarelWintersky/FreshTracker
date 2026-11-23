<?php

declare(strict_types=1);

namespace FreshTracker\Controllers;

use FreshTracker\Bootstrap\App;
use FreshTracker\Http\Response;

final class ConfigController
{
    public function get(): never
    {
        $context = App::context();

        Response::ok([
            'app' => [
                'name'    => (string) App::config('app.name', 'FreshTracker'),
                'version' => App::version(),
                'theme'   => (string) App::config('app.theme', 'day'),
            ],
            'access' => [
                'group'          => $context->group,
                'can_view'       => $context->canView,
                'can_edit'       => $context->canEdit,
                'password_scope' => $context->editPasswordScope,
            ],
            'defaults' => [
                'threshold_days' => (int) App::config('defaults.threshold_days', 7),
            ],
            'units' => [
                'weight' => ['g', 'kg'],
            ],
        ]);
    }
}
