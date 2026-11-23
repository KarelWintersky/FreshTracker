<?php

declare(strict_types=1);

namespace FreshTracker\Repositories;

use FreshTracker\AppDatabase;
use FreshTracker\Domain\ProductStatus;

final class ProductRepository
{
    public function __construct(private readonly AppDatabase $db)
    {
    }

    /** @return list<array<string, mixed>> */
    public function active(?string $typeSlug = null, string $query = ''): array
    {
        return $this->select(
            "p.status = 'active'",
            'ORDER BY p.expiry_date ASC, p.id ASC',
            $typeSlug,
            $query
        );
    }

    /** @return list<array<string, mixed>> */
    public function history(?string $typeSlug = null, string $query = ''): array
    {
        return $this->select(
            "p.status <> 'active'",
            'ORDER BY COALESCE(p.closed_at, p.updated_at) DESC, p.id DESC',
            $typeSlug,
            $query
        );
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        $days = $this->db->sqlDaysRemaining('p.expiry_date');

        return $this->db->fetchOne(
            "SELECT p.*, t.slug AS type, t.title AS type_title, {$days} AS days_remaining
             FROM products p
             JOIN types t ON t.id = p.type_id
             WHERE p.id = ?",
            [$id]
        );
    }

    public function create(array $data): int
    {
        return $this->db->insert(
            'INSERT INTO products (name, type_id, weight_g, expiry_date, threshold_days, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $data['name'],
                $data['type_id'],
                $data['weight_g'],
                $data['expiry_date'],
                $data['threshold_days'],
                ProductStatus::Active->value,
                date('Y-m-d H:i:s'),
                date('Y-m-d H:i:s'),
            ]
        );
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = [];

        foreach (['name', 'type_id', 'weight_g', 'expiry_date', 'threshold_days'] as $column) {
            if (array_key_exists($column, $data)) {
                $fields[] = "{$column} = ?";
                $params[] = $data[$column];
            }
        }

        if ($fields === []) {
            return;
        }

        $fields[] = 'updated_at = ' . $this->db->sqlNow();
        $params[] = $id;

        $this->db->execute(
            'UPDATE products SET ' . implode(', ', $fields) . ' WHERE id = ?',
            $params
        );
    }

    public function close(int $id, ProductStatus $status): bool
    {
        if ($status === ProductStatus::Active) {
            return false;
        }

        $now = $this->db->sqlNow();
        $days = $this->db->sqlDaysRemaining('expiry_date');

        return $this->db->execute(
            "UPDATE products
             SET status = ?, closed_at = {$now}, updated_at = {$now},
                 expiry_days_at_close = {$days}
             WHERE id = ? AND status = 'active'",
            [$status->value, $id]
        ) > 0;
    }

    public function restore(int $id): bool
    {
        $now = $this->db->sqlNow();

        return $this->db->execute(
            "UPDATE products
             SET status = 'active', closed_at = NULL, expiry_days_at_close = NULL, updated_at = {$now}
             WHERE id = ? AND status <> 'active'",
            [$id]
        ) > 0;
    }

    public function delete(int $id): bool
    {
        return $this->db->execute('DELETE FROM products WHERE id = ?', [$id]) > 0;
    }

    /** @return list<array<string, mixed>> */
    private function select(string $condition, string $orderBy, ?string $typeSlug, string $query): array
    {
        $days = $this->db->sqlDaysRemaining('p.expiry_date');

        $sql = "SELECT p.*, t.slug AS type, t.title AS type_title, {$days} AS days_remaining
                FROM products p
                JOIN types t ON t.id = p.type_id
                WHERE {$condition}";
        $params = [];

        if ($typeSlug !== null && $typeSlug !== '') {
            $sql .= ' AND t.slug = ?';
            $params[] = $typeSlug;
        }

        if ($query !== '') {
            $sql .= ' AND p.name LIKE ?';
            $params[] = '%' . $query . '%';
        }

        $sql .= ' ' . $orderBy;

        return $this->db->fetchAll($sql, $params);
    }
}
