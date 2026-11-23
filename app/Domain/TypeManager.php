<?php

declare(strict_types=1);

namespace FreshTracker\Domain;

use FreshTracker\AppDatabase;
use FreshTracker\Http\HttpException;
use FreshTracker\Repositories\TypeRepository;

/**
 * Type CRUD rules: slugs, uniqueness, "разное" protection and safe deletion.
 */
final class TypeManager
{
    public const SYSTEM_SLUG = 'разное';

    private TypeRepository $types;

    public function __construct(private readonly AppDatabase $db)
    {
        $this->types = new TypeRepository($db);
    }

    /** @return array<string, mixed> */
    public function create(array $data): array
    {
        $normalized = $this->validate($data);
        $normalized['slug'] = $this->uniqueSlug($this->slugify($normalized['title']));
        $normalized['sort_order'] = $this->types->nextSortOrder();

        $id = $this->types->create($normalized);

        return $this->types->find($id) ?? [];
    }

    /** @return array<string, mixed> */
    public function update(int $id, array $data): array
    {
        $this->require($id);
        $normalized = $this->validate($data, $id);

        $this->types->update($id, $normalized);

        return $this->types->find($id) ?? [];
    }

    /**
     * Deletes a type and moves its products to the system type.
     * Product names get a "[type] " prefix to keep the old category visible.
     *
     * @return array{deleted: int, moved_products: int, fallback: string}
     */
    public function delete(int $id): array
    {
        $type = $this->require($id);

        if ($type['slug'] === self::SYSTEM_SLUG) {
            throw new HttpException('Системный тип «' . $type['title'] . '» удалить нельзя', 409);
        }

        $fallback = $this->types->findBySlug(self::SYSTEM_SLUG);
        if ($fallback === null) {
            throw new HttpException('Системный тип «Разное» не найден', 500);
        }

        $prefix = '[' . $type['title'] . '] ';

        $moved = $this->db->transaction(function () use ($id, $fallback, $prefix): int {
            $moved = $this->types->reassignProducts($id, (int) $fallback['id'], $prefix);
            $this->types->delete($id);

            return $moved;
        });

        return [
            'deleted'        => $id,
            'moved_products' => $moved,
            'fallback'       => (string) $fallback['title'],
        ];
    }

    public function move(int $id, string $direction): bool
    {
        $this->require($id);

        if (!in_array($direction, ['up', 'down'], true)) {
            throw new HttpException('Неизвестное направление', 422);
        }

        return $this->db->transaction(function () use ($id, $direction): bool {
            $ids = $this->types->orderedIds();
            $index = array_search($id, $ids, true);

            if ($index === false) {
                return false;
            }

            $target = $direction === 'up' ? $index - 1 : $index + 1;
            if ($target < 0 || $target >= count($ids)) {
                return false;
            }

            [$ids[$index], $ids[$target]] = [$ids[$target], $ids[$index]];

            foreach ($ids as $order => $typeId) {
                $this->types->setSortOrder($typeId, $order);
            }

            return true;
        });
    }

    /** @return array<string, mixed> */
    private function require(int $id): array
    {
        $type = $this->types->find($id);

        if ($type === null) {
            throw new HttpException('Тип не найден', 404);
        }

        return $type;
    }

    /** @return array{title: string, default_expiry_days: int, default_threshold_days: int, default_weight_g: int|null} */
    private function validate(array $data, ?int $exceptId = null): array
    {
        $title = trim((string) ($data['title'] ?? ''));

        if ($title === '') {
            throw new HttpException('Название типа обязательно', 422);
        }

        if (mb_strlen($title) > 128) {
            throw new HttpException('Название типа не должно превышать 128 символов', 422);
        }

        if ($this->types->titleExists($title, $exceptId)) {
            throw new HttpException('Тип с таким названием уже есть', 422);
        }

        $expiry = filter_var($data['default_expiry_days'] ?? 30, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 3650],
        ]);
        if ($expiry === false) {
            throw new HttpException('Срок по умолчанию должен быть от 1 до 3650 дней', 422);
        }

        $threshold = filter_var($data['default_threshold_days'] ?? 7, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 365],
        ]);
        if ($threshold === false) {
            throw new HttpException('Порог предупреждения должен быть от 1 до 365 дней', 422);
        }

        $weight = $data['default_weight_g'] ?? null;
        if ($weight === '' || $weight === null) {
            $weight = null;
        } else {
            $weight = filter_var($weight, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 5_000_000],
            ]);
            if ($weight === false) {
                throw new HttpException('Вес по умолчанию должен быть положительным числом', 422);
            }
            $weight = (int) $weight;
        }

        return [
            'title'                  => $title,
            'default_expiry_days'    => (int) $expiry,
            'default_threshold_days' => (int) $threshold,
            'default_weight_g'       => $weight,
        ];
    }

    private function slugify(string $title): string
    {
        $slug = mb_strtolower(trim($title));
        $slug = preg_replace('/\s+/u', '_', $slug) ?? '';
        $slug = preg_replace('/[^\p{L}\p{N}_-]+/u', '', $slug) ?? '';
        $slug = trim($slug, '_-');

        return $slug !== '' ? $slug : 'type';
    }

    private function uniqueSlug(string $base): string
    {
        $slug = $base;
        $suffix = 2;

        while ($this->types->findBySlug($slug) !== null) {
            $slug = $base . '_' . $suffix;
            $suffix++;
        }

        return $slug;
    }
}
