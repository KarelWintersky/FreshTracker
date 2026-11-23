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
        $theme = strtolower((string) App::config('app.theme', 'day'));
        if (!in_array($theme, ['day', 'night'], true)) {
            $theme = 'day';
        }

        Response::ok([
            'app' => [
                'name'    => (string) App::config('app.name', 'FreshTracker'),
                'version' => App::version(),
                'theme'   => $theme,
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
            'features' => [
                'product_drafts' => (bool) App::config('features.product_drafts', false),
            ],
            'units' => [
                'weight' => ['g', 'kg'],
            ],
        ]);
    }
}
