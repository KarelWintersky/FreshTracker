<?php

declare(strict_types=1);

namespace FreshTracker\Controllers;

use FreshTracker\Bootstrap\App;
use FreshTracker\Domain\TypeManager;
use FreshTracker\Http\HttpException;
use FreshTracker\Http\Input;
use FreshTracker\Http\Response;
use FreshTracker\Repositories\TypeRepository;

final class TypesController
{
    public function index(): never
    {
        $types = (new TypeRepository(App::db()))->allWithCounts();

        Response::ok(array_map(fn (array $type): array => $this->format($type), $types));
    }

    public function store(): never
    {
        $this->assertManager();

        $type = (new TypeManager(App::db()))->create(Input::json());

        Response::ok($this->format($type), 201);
    }

    public function update(string $id): never
    {
        $this->assertManager();

        $type = (new TypeManager(App::db()))->update($this->parseId($id), Input::json());

        Response::ok($this->format($type));
    }

    public function destroy(string $id): never
    {
        $this->assertManager();

        Response::ok((new TypeManager(App::db()))->delete($this->parseId($id)));
    }

    public function move(string $id): never
    {
        $this->assertManager();

        $data = Input::json();
        $moved = (new TypeManager(App::db()))->move($this->parseId($id), (string) ($data['direction'] ?? ''));

        Response::ok(['moved' => $moved]);
    }

    /** Type management is local-only, even when public editing is enabled. */
    private function assertManager(): void
    {
        $context = App::context();

        if ($context->group !== 'local' || !$context->canEdit) {
            throw new HttpException('Управление типами доступно только в локальной сети с правом редактирования', 403);
        }
    }

    private function parseId(string $raw): int
    {
        if (!ctype_digit($raw) || (int) $raw < 1) {
            throw new HttpException('Некорректный идентификатор', 400);
        }

        return (int) $raw;
    }

    /** @return array<string, mixed> */
    private function format(array $type): array
    {
        return [
            'id'                     => (int) $type['id'],
            'slug'                   => (string) $type['slug'],
            'title'                  => (string) $type['title'],
            'default_expiry_days'    => (int) $type['default_expiry_days'],
            'default_threshold_days' => (int) $type['default_threshold_days'],
            'default_weight_g'       => $type['default_weight_g'] === null ? null : (int) $type['default_weight_g'],
            'sort_order'             => (int) ($type['sort_order'] ?? 0),
            'product_count'          => (int) ($type['product_count'] ?? 0),
            'active_count'           => (int) ($type['active_count'] ?? 0),
            'is_system'              => ($type['slug'] ?? '') === TypeManager::SYSTEM_SLUG,
        ];
    }
}
