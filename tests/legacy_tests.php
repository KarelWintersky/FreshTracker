<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use FreshTracker\Access\AccessContext;
use FreshTracker\Access\AccessPolicy;
use FreshTracker\Access\EditSession;
use FreshTracker\AppDatabase;
use FreshTracker\Domain\Expiry;
use FreshTracker\Domain\ProductStatus;
use FreshTracker\Domain\TypeManager;
use FreshTracker\Domain\Weight;
use FreshTracker\Http\HttpException;
use FreshTracker\Migrations\Migrator;
use FreshTracker\Repositories\TypeRepository;
use Psr\Log\NullLogger;

$failures = 0;
$passed = 0;

function test(string $name, callable $callback): void
{
    global $failures, $passed;

    try {
        $callback();
        $passed++;
        echo "  ok   {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "  FAIL {$name}\n       {$e->getMessage()}\n";
    }
}

function check(bool $condition, string $message = 'assertion failed'): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function same(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message !== '' ? $message . ': ' : '')
            . 'expected ' . var_export($expected, true)
            . ', got ' . var_export($actual, true)
        );
    }
}

function throws(callable $callback, string $class = Throwable::class): void
{
    try {
        $callback();
    } catch (Throwable $e) {
        if ($e instanceof $class) {
            return;
        }
        throw new RuntimeException('unexpected exception: ' . $e::class);
    }

    throw new RuntimeException('expected exception was not thrown');
}

function session(): EditSession
{
    return new EditSession('test-secret');
}

function policy(array $access): AccessPolicy
{
    return AccessPolicy::fromConfig($access, new NullLogger());
}

echo "Weight\n";

test('grams stay grams', function (): void {
    $weight = Weight::fromInput(900, 'g');
    same(900, $weight->grams);
    same('900 г', $weight->format());
});

test('kilograms convert to grams', function (): void {
    $weight = Weight::fromInput(1.5, 'kg');
    same(1500, $weight->grams);
    same('1,5 кг', $weight->format());
});

test('whole kilograms are formatted without decimals', function (): void {
    same('1 кг', Weight::fromGrams(1000)->format());
    same('2 кг', Weight::fromGrams(2000)->format());
});

test('zero weight is rejected', function (): void {
    throws(static fn () => Weight::fromInput(0, 'g'), InvalidArgumentException::class);
    throws(static fn () => Weight::fromGrams(-1), InvalidArgumentException::class);
});

echo "Expiry\n";

test('today is zero days left', function (): void {
    $today = new DateTimeImmutable('today');
    same(0, Expiry::daysLeft($today->format('Y-m-d'), $today));
});

test('tomorrow is one day left', function (): void {
    $today = new DateTimeImmutable('today');
    same(1, Expiry::daysLeft($today->modify('+1 day')->format('Y-m-d'), $today));
});

test('yesterday is minus one day', function (): void {
    $today = new DateTimeImmutable('today');
    same(-1, Expiry::daysLeft($today->modify('-1 day')->format('Y-m-d'), $today));
});

test('date normalization accepts common formats', function (): void {
    same('2026-12-31', Expiry::normalize('31.12.2026'));
    same('2026-12-31', Expiry::normalize('2026-12-31'));
    same(null, Expiry::normalize('31.13.2026'));
    same(null, Expiry::normalize(''));
});

test('fromDays builds a date', function (): void {
    $today = new DateTimeImmutable('2026-01-01');
    same('2026-01-04', Expiry::fromDays(3, $today));
    same('2025-12-31', Expiry::fromDays(-1, $today));
});

echo "Status\n";

test('status labels', function (): void {
    same('В запасе', ProductStatus::Active->label());
    same('Использован', ProductStatus::Used->label());
    same('Выброшен', ProductStatus::Disposed->label());
    check(ProductStatus::Used->isClosed());
    check(!ProductStatus::Active->isClosed());
});

echo "Access policy\n";

test('local network without password can edit', function (): void {
    $context = policy(['local' => ['networks' => ['127.0.0.1'], 'visible' => true, 'password' => '']])
        ->context('127.0.0.1', session());

    same('local', $context->group);
    check($context->canView);
    check($context->canEdit);
    same('', $context->editPasswordScope);
});

test('local password locks editing until granted', function (): void {
    $policy = policy(['local' => ['networks' => ['127.0.0.1'], 'visible' => true, 'password' => 'secret']]);
    $context = $policy->context('127.0.0.1', session());

    check($context->canView);
    check(!$context->canEdit);
    same('local', $context->editPasswordScope);
    check($policy->canRequestPassword('local'));
    check($policy->verifyPassword('local', 'secret'));
    check(!$policy->verifyPassword('local', 'nope'));
});

test('local visible false denies everything', function (): void {
    $context = policy(['local' => ['networks' => ['127.0.0.1'], 'visible' => false, 'password' => '']])
        ->context('127.0.0.1', session());

    same('none', $context->group);
    check(!$context->canView);
    check(!$context->canEdit);
});

test('public is read-only by default', function (): void {
    $context = policy([])->context('8.8.8.8', session());

    same('public', $context->group);
    check($context->canView);
    check(!$context->canEdit);
});

test('public editable always allows editing', function (): void {
    $context = policy(['public' => ['visible' => 'always', 'password' => '', 'editable' => 'always']])
        ->context('8.8.8.8', session());

    check($context->canEdit);
});

test('public editable password requires a password', function (): void {
    $policy = policy(['public' => ['visible' => 'always', 'password' => 'letmein', 'editable' => 'password']]);
    $context = $policy->context('8.8.8.8', session());

    check(!$context->canEdit);
    same('public', $context->editPasswordScope);
    check($policy->canRequestPassword('public'));
    check($policy->verifyPassword('public', 'letmein'));
});

test('public editable password with empty password fails closed', function (): void {
    $policy = policy(['public' => ['visible' => 'always', 'password' => '', 'editable' => 'password']]);
    $context = $policy->context('8.8.8.8', session());

    check(!$context->canEdit);
    check(!$policy->canRequestPassword('public'));
});

test('ipv6 localhost is local', function (): void {
    $policy = policy(['local' => ['networks' => ['::1'], 'visible' => true, 'password' => '']]);

    check($policy->isLocal('::1'));
    check(!$policy->isLocal('::2'));
});

test('legacy access.admin is mapped to local networks', function (): void {
    $context = policy(['admin' => ['127.0.0.1']])->context('127.0.0.1', session());

    same('local', $context->group);
});

test('public can be hidden completely', function (): void {
    $policy = policy(['public' => ['visible' => false, 'password' => '', 'editable' => 'never']]);
    $context = $policy->context('8.8.8.8', session());

    same('none', $context->group);
    check(!$context->canView);
    check(!$context->canEdit);
    check(!$policy->canRequestPassword('public'));
});

echo "Database and migration\n";

test('legacy schema is rebuilt into grams and statuses', function (): void {
    $db = new AppDatabase(['driver' => 'sqlite', 'path' => ':memory:']);

    $db->execute('CREATE TABLE products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        weight REAL NOT NULL,
        expiry_date TEXT NOT NULL,
        type TEXT NOT NULL,
        threshold_days INTEGER DEFAULT 7,
        is_deleted BOOLEAN DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        deleted_at DATETIME NULL
    )');

    $db->execute(
        "INSERT INTO products (name, weight, expiry_date, type, is_deleted, deleted_at) VALUES
         ('Крупа', 0.9, '2026-12-01', 'крупы', 0, NULL),
         ('Масло', 1.5, '2026-01-01', 'масло', 1, '2026-02-01 10:00:00')"
    );

    (new Migrator($db))->ensure();

    $columns = $db->getDriver()->tableColumns('products');
    check(isset($columns['weight_g']), 'weight_g column is missing');
    check(isset($columns['status']), 'status column is missing');
    check(isset($columns['closed_at']), 'closed_at column is missing');
    check(!isset($columns['is_deleted']), 'legacy is_deleted column should be gone');

    $rows = $db->fetchAll('SELECT * FROM products ORDER BY id ASC');
    same(2, count($rows));
    same(900, (int) $rows[0]['weight_g']);
    same('active', $rows[0]['status']);
    same(1500, (int) $rows[1]['weight_g']);
    same('disposed', $rows[1]['status']);
    check((int) $rows[0]['type_id'] > 0, 'type_id was not backfilled');
    same(1, (int) $db->fetchValue('PRAGMA user_version'));
});

test('migration is idempotent', function (): void {
    $db = new AppDatabase(['driver' => 'sqlite', 'path' => ':memory:']);
    $migrator = new Migrator($db);
    $migrator->ensure();
    $migrator->ensure();

    same(8, (int) $db->fetchValue('SELECT COUNT(*) FROM types'));
    same(1, (int) $db->fetchValue('PRAGMA user_version'));
});

test('days_remaining compares dates, not timestamps', function (): void {
    $db = new AppDatabase(['driver' => 'sqlite', 'path' => ':memory:']);
    (new Migrator($db))->ensure();

    $db->execute(
        "INSERT INTO products (name, type_id, weight_g, expiry_date, threshold_days, status, created_at, updated_at)
         VALUES ('Сегодня', 1, 100, date('now'), 7, 'active', datetime('now'), datetime('now'))"
    );

    $days = $db->fetchValue('SELECT ' . $db->sqlDaysRemaining('expiry_date') . ' FROM products');
    same(0, (int) $days);
});

echo "Types\n";

test('type create generates a unique slug', function (): void {
    $db = new AppDatabase(['driver' => 'sqlite', 'path' => ':memory:']);
    (new Migrator($db))->ensure();
    $manager = new TypeManager($db);

    $first = $manager->create([
        'title' => 'Заморозка',
        'default_expiry_days' => 180,
        'default_threshold_days' => 10,
        'default_weight_g' => 500,
    ]);

    same('zamorozka', $first['slug']);
    same('Заморозка', $first['title']);
    same(180, (int) $first['default_expiry_days']);

    $second = $manager->create(['title' => 'Заморозка!']);
    same('zamorozka_2', $second['slug']);
});

test('type slug can be set manually and is normalized', function (): void {
    $db = new AppDatabase(['driver' => 'sqlite', 'path' => ':memory:']);
    (new Migrator($db))->ensure();
    $manager = new TypeManager($db);

    $type = $manager->create(['title' => 'Фрукты', 'slug' => 'Fruits!']);
    same('fruits', $type['slug']);

    $cyrillic = $manager->create(['title' => 'Овощи', 'slug' => 'Овощи']);
    same('овощи', $cyrillic['slug']);
});

test('manual slug must be unique', function (): void {
    $db = new AppDatabase(['driver' => 'sqlite', 'path' => ':memory:']);
    (new Migrator($db))->ensure();
    $manager = new TypeManager($db);

    $manager->create(['title' => 'Фрукты', 'slug' => 'fruits']);

    try {
        $manager->create(['title' => 'Ягоды', 'slug' => 'Fruits']);
        throw new RuntimeException('duplicate slug was accepted');
    } catch (HttpException $e) {
        same(422, $e->status);
    }
});

test('type slug can be changed on update', function (): void {
    $db = new AppDatabase(['driver' => 'sqlite', 'path' => ':memory:']);
    (new Migrator($db))->ensure();
    $manager = new TypeManager($db);

    $type = $manager->create(['title' => 'Ягоды']);
    $updated = $manager->update((int) $type['id'], ['title' => 'Ягоды', 'slug' => 'berries']);

    same('berries', $updated['slug']);
});

test('system type slug is immutable', function (): void {
    $db = new AppDatabase(['driver' => 'sqlite', 'path' => ':memory:']);
    (new Migrator($db))->ensure();
    $types = new TypeRepository($db);
    $manager = new TypeManager($db);
    $system = $types->findBySlug(TypeManager::SYSTEM_SLUG);

    try {
        $manager->update((int) $system['id'], ['title' => 'Разное', 'slug' => 'other']);
        throw new RuntimeException('system slug was changed');
    } catch (HttpException $e) {
        same(422, $e->status);
    }

    $renamed = $manager->update((int) $system['id'], ['title' => 'Прочее', 'slug' => TypeManager::SYSTEM_SLUG]);
    same('разное', $renamed['slug']);
    same('Прочее', $renamed['title']);
});

test('type titles are unique case-insensitively', function (): void {
    $db = new AppDatabase(['driver' => 'sqlite', 'path' => ':memory:']);
    (new Migrator($db))->ensure();
    $manager = new TypeManager($db);

    $manager->create(['title' => 'Заморозка']);

    try {
        $manager->create(['title' => 'заморозка']);
        throw new RuntimeException('duplicate title was accepted');
    } catch (HttpException $e) {
        same(422, $e->status);
    }
});

test('the system type cannot be deleted', function (): void {
    $db = new AppDatabase(['driver' => 'sqlite', 'path' => ':memory:']);
    (new Migrator($db))->ensure();
    $types = new TypeRepository($db);
    $system = $types->findBySlug(TypeManager::SYSTEM_SLUG);

    try {
        (new TypeManager($db))->delete((int) $system['id']);
        throw new RuntimeException('system type was deleted');
    } catch (HttpException $e) {
        same(409, $e->status);
    }
});

test('deleting a type moves products and prefixes their names', function (): void {
    $db = new AppDatabase(['driver' => 'sqlite', 'path' => ':memory:']);
    (new Migrator($db))->ensure();
    $types = new TypeRepository($db);
    $krupy = $types->findBySlug('крупы');
    $fallback = $types->findBySlug(TypeManager::SYSTEM_SLUG);

    $db->execute(
        "INSERT INTO products (name, type_id, weight_g, expiry_date, threshold_days, status, created_at, updated_at)
         VALUES ('Гречка', ?, 900, '2026-12-01', 7, 'active', datetime('now'), datetime('now'))",
        [(int) $krupy['id']]
    );
    $db->execute(
        "INSERT INTO products (name, type_id, weight_g, expiry_date, threshold_days, status, created_at, updated_at)
         VALUES ('[Крупы] Мука', ?, 1000, '2026-12-01', 7, 'active', datetime('now'), datetime('now'))",
        [(int) $krupy['id']]
    );

    $result = (new TypeManager($db))->delete((int) $krupy['id']);

    same(2, $result['moved_products']);
    same(null, $types->findBySlug('крупы'));

    $rows = $db->fetchAll('SELECT name, type_id FROM products ORDER BY id ASC');
    same('[Крупы] Гречка', $rows[0]['name']);
    same('[Крупы] Мука', $rows[1]['name'], 'already prefixed name must not get a second prefix');
    same((int) $fallback['id'], (int) $rows[0]['type_id']);
    same((int) $fallback['id'], (int) $rows[1]['type_id']);
});

test('types can be reordered', function (): void {
    $db = new AppDatabase(['driver' => 'sqlite', 'path' => ':memory:']);
    (new Migrator($db))->ensure();
    $types = new TypeRepository($db);
    $manager = new TypeManager($db);

    $before = $types->orderedIds();
    same(true, $manager->move($before[1], 'up'));
    $after = $types->orderedIds();

    same($before[1], $after[0]);
    same($before[0], $after[1]);

    same(false, $manager->move($after[0], 'up'), 'moving the first item up is a no-op');
});

test('unknown move direction is rejected', function (): void {
    $db = new AppDatabase(['driver' => 'sqlite', 'path' => ':memory:']);
    (new Migrator($db))->ensure();
    $types = new TypeRepository($db);

    try {
        (new TypeManager($db))->move($types->orderedIds()[0], 'left');
        throw new RuntimeException('unknown direction was accepted');
    } catch (HttpException $e) {
        same(422, $e->status);
    }
});

echo "\n" . ($passed + $failures) . " tests, {$passed} passed, {$failures} failed\n";

exit($failures === 0 ? 0 : 1);
