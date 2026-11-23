<?php

declare(strict_types=1);

namespace FreshTracker\Controllers;

use FreshTracker\Bootstrap\App;
use FreshTracker\Http\Response;
use FreshTracker\Repositories\TypeRepository;

final class TypesController
{
    public function index(): never
    {
        $types = (new TypeRepository(App::db()))->all();

        Response::ok(array_map(static fn (array $type): array => [
            'id'                     => (int) $type['id'],
            'slug'                   => (string) $type['slug'],
            'title'                  => (string) $type['title'],
            'default_expiry_days'    => (int) $type['default_expiry_days'],
            'default_threshold_days' => (int) $type['default_threshold_days'],
            'default_weight_g'       => $type['default_weight_g'] === null ? null : (int) $type['default_weight_g'],
        ], $types));
    }
}
