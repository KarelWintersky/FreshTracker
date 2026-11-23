<?php

declare(strict_types=1);

namespace FreshTracker\Repositories;

use FreshTracker\AppDatabase;

final class TypeRepository
{
    public function __construct(private readonly AppDatabase $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function all(): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM types ORDER BY sort_order ASC, id ASC'
        );
    }

    /** @return list<array<string, mixed>> types with a product_count field */
    public function allWithCounts(): array
    {
        return $this->db->fetchAll(
            'SELECT t.*,
                    (SELECT COUNT(*) FROM products p WHERE p.type_id = t.id) AS product_count,
                    (SELECT COUNT(*) FROM products p WHERE p.type_id = t.id AND p.status = \'active\') AS active_count
             FROM types t
             ORDER BY t.sort_order ASC, t.id ASC'
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM types WHERE id = ?', [$id]);
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->db->fetchOne('SELECT * FROM types WHERE slug = ?', [$slug]);
    }

    /** @return array<string, mixed>|null */
    public function findBySlugOrId(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return $this->findBySlug('разное');
        }

        if (is_int($value) || (is_string($value) && ctype_digit($value))) {
            return $this->find((int) $value);
        }

        if (is_string($value)) {
            return $this->findBySlug($value);
        }

        return null;
    }

    public function titleExists(string $title, ?int $exceptId = null): bool
    {
        $needle = mb_strtolower(trim($title));

        foreach ($this->db->fetchAll('SELECT id, title FROM types') as $row) {
            if ($exceptId !== null && (int) $row['id'] === $exceptId) {
                continue;
            }
            if (mb_strtolower((string) $row['title']) === $needle) {
                return true;
            }
        }

        return false;
    }

    /** @return list<int> */
    public function orderedIds(): array
    {
        $ids = [];
        foreach ($this->db->fetchAll('SELECT id FROM types ORDER BY sort_order ASC, id ASC') as $row) {
            $ids[] = (int) $row['id'];
        }

        return $ids;
    }

    public function nextSortOrder(): int
    {
        return (int) $this->db->fetchValue('SELECT COALESCE(MAX(sort_order), -1) + 1 FROM types');
    }

    public function create(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO types (slug, title, default_expiry_days, default_threshold_days, default_weight_g, sort_order)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $data['slug'],
                $data['title'],
                $data['default_expiry_days'],
                $data['default_threshold_days'],
                $data['default_weight_g'],
                $data['sort_order'],
            ]
        );
    }

    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE types
             SET slug = ?, title = ?, default_expiry_days = ?, default_threshold_days = ?, default_weight_g = ?
             WHERE id = ?',
            [
                $data['slug'],
                $data['title'],
                $data['default_expiry_days'],
                $data['default_threshold_days'],
                $data['default_weight_g'],
                $id,
            ]
        );
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        if ($exceptId === null) {
            return (bool) $this->db->fetchValue('SELECT 1 FROM types WHERE slug = ?', [$slug]);
        }

        return (bool) $this->db->fetchValue('SELECT 1 FROM types WHERE slug = ? AND id <> ?', [$slug, $exceptId]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM types WHERE id = ?', [$id]);
    }

    public function setSortOrder(int $id, int $order): void
    {
        $this->db->execute('UPDATE types SET sort_order = ? WHERE id = ?', [$order, $id]);
    }

    /** @return list<array{id: int, name: string}> */
    public function productsOfType(int $typeId): array
    {
        $rows = $this->db->fetchAll('SELECT id, name FROM products WHERE type_id = ? ORDER BY id ASC', [$typeId]);

        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['name']],
            $rows
        );
    }

    /** Moves all products to another type, prefixing names when needed. Returns the number moved. */
    public function reassignProducts(int $fromTypeId, int $toTypeId, string $prefix): int
    {
        $products = $this->productsOfType($fromTypeId);

        foreach ($products as $product) {
            $name = $product['name'];
            if (!str_starts_with($name, $prefix)) {
                $name = $prefix . $name;
            }

            $this->db->execute(
                'UPDATE products SET name = ?, type_id = ?, updated_at = ? WHERE id = ?',
                [$name, $toTypeId, date('Y-m-d H:i:s'), $product['id']]
            );
        }

        return count($products);
    }
}
