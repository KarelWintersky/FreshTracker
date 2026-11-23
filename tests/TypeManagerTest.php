<?php

declare(strict_types=1);

namespace FreshTracker\Tests;

use FreshTracker\Domain\TypeManager;
use FreshTracker\Http\HttpException;
use FreshTracker\Tests\Support\DatabaseTestCase;

final class TypeManagerTest extends DatabaseTestCase
{
    public function testCreateGeneratesUniqueTransliteratedSlug(): void
    {
        $manager = $this->manager();

        $first = $manager->create(['title' => 'Заморозка']);
        self::assertSame('zamorozka', $first['slug']);

        $second = $manager->create(['title' => 'Заморозка!']);
        self::assertSame('zamorozka_2', $second['slug']);
    }

    public function testTitleMustBeUniqueCaseInsensitively(): void
    {
        $manager = $this->manager();
        $manager->create(['title' => 'Заморозка']);

        $this->assertHttpStatus(422, static fn () => $manager->create(['title' => 'заморозка']));
    }

    public function testManualSlugIsNormalized(): void
    {
        $manager = $this->manager();

        self::assertSame('fruits', $manager->create(['title' => 'Фрукты', 'slug' => 'Fruits!'])['slug']);
        self::assertSame('овощи', $manager->create(['title' => 'Овощи', 'slug' => 'Овощи'])['slug']);
    }

    public function testManualSlugMustBeUnique(): void
    {
        $manager = $this->manager();
        $manager->create(['title' => 'Фрукты', 'slug' => 'fruits']);

        $this->assertHttpStatus(422, static fn () => $manager->create(['title' => 'Ягоды', 'slug' => 'Fruits']));
    }

    public function testSlugCanBeChangedOnUpdate(): void
    {
        $manager = $this->manager();
        $type = $manager->create(['title' => 'Ягоды']);

        $updated = $manager->update((int) $type['id'], ['title' => 'Ягоды', 'slug' => 'berries']);

        self::assertSame('berries', $updated['slug']);
    }

    public function testSystemSlugIsImmutable(): void
    {
        $manager = $this->manager();
        $system = $this->types()->findBySlug(TypeManager::SYSTEM_SLUG);
        self::assertNotNull($system);

        $this->assertHttpStatus(422, static fn () => $manager->update(
            (int) $system['id'],
            ['title' => 'Разное', 'slug' => 'other']
        ));

        $renamed = $manager->update((int) $system['id'], [
            'title' => 'Прочее',
            'slug' => TypeManager::SYSTEM_SLUG,
        ]);

        self::assertSame('разное', $renamed['slug']);
        self::assertSame('Прочее', $renamed['title']);
    }

    public function testSystemTypeCannotBeDeleted(): void
    {
        $manager = $this->manager();
        $system = $this->types()->findBySlug(TypeManager::SYSTEM_SLUG);
        self::assertNotNull($system);

        $this->assertHttpStatus(409, static fn () => $manager->delete((int) $system['id']));
    }

    public function testDeletingTypeMovesAndPrefixesProducts(): void
    {
        $manager = $this->manager();
        $krupy = $this->types()->findBySlug('крупы');
        $fallback = $this->types()->findBySlug(TypeManager::SYSTEM_SLUG);
        self::assertNotNull($krupy);
        self::assertNotNull($fallback);

        $this->insertProduct('Гречка', (int) $krupy['id']);
        $this->insertProduct('[Крупы] Мука', (int) $krupy['id']);

        $result = $manager->delete((int) $krupy['id']);

        self::assertSame(2, $result['moved_products']);
        self::assertNull($this->types()->findBySlug('крупы'));

        $rows = $this->db->fetchAll('SELECT name, type_id FROM products ORDER BY id ASC');
        self::assertSame('[Крупы] Гречка', $rows[0]['name']);
        self::assertSame('[Крупы] Мука', $rows[1]['name']);
        self::assertSame((int) $fallback['id'], (int) $rows[0]['type_id']);
        self::assertSame((int) $fallback['id'], (int) $rows[1]['type_id']);
    }

    public function testTypesCanBeReordered(): void
    {
        $manager = $this->manager();
        $before = $this->types()->orderedIds();

        self::assertTrue($manager->move($before[1], 'up'));
        $after = $this->types()->orderedIds();

        self::assertSame($before[1], $after[0]);
        self::assertSame($before[0], $after[1]);
        self::assertFalse($manager->move($after[0], 'up'));
    }

    public function testUnknownMoveDirectionIsRejected(): void
    {
        $manager = $this->manager();
        $first = $this->types()->orderedIds()[0];

        $this->assertHttpStatus(422, static fn () => $manager->move($first, 'left'));
    }

    private function manager(): TypeManager
    {
        return new TypeManager($this->db);
    }

    private function insertProduct(string $name, int $typeId): void
    {
        $this->db->execute(
            'INSERT INTO products (name, type_id, weight_g, expiry_date, threshold_days, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$name, $typeId, 900, '2026-12-01', 7, 'active', date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]
        );
    }

    private function assertHttpStatus(int $expected, callable $callback): void
    {
        try {
            $callback();
        } catch (HttpException $e) {
            self::assertSame($expected, $e->status);

            return;
        }

        self::fail('Expected HttpException with status ' . $expected);
    }
}
