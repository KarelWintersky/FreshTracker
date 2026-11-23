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
}
