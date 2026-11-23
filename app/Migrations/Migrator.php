<?php

declare(strict_types=1);

namespace FreshTracker\Migrations;

use FreshTracker\AppDatabase;
use FreshTracker\Config\TypeDefaults;
use FreshTracker\Database\DatabaseDriver;
use FreshTracker\Database\SQLiteDriver;

final class Migrator
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private readonly AppDatabase $db)
    {
    }

    /** Idempotent: called on boot and from the CLI. */
    public function ensure(): void
    {
        $driver = $this->db->getDriver();

        if (!$driver instanceof SQLiteDriver) {
            $driver->createTables();
            $this->seedTypes($driver);

            return;
        }

        if (!$driver->tableExists('products')) {
            $driver->createTables();
            $this->seedTypes($driver);
            $driver->setUserVersion(self::SCHEMA_VERSION);

            return;
        }

        if ($driver->userVersion() < self::SCHEMA_VERSION) {
            // Old schema: only auxiliary tables first, then rebuild products.
            // createTables() is unsafe here: its indexes reference `status`,
            // which does not exist in the legacy table yet.
            $driver->createAuxTables();
            $this->seedTypes($driver);
            $this->rebuildSqlite($driver);

            return;
        }

        if ((int) $driver->fetchValue('SELECT COUNT(*) FROM types') === 0) {
            $this->seedTypes($driver);
        }
    }

    private function seedTypes(DatabaseDriver $driver): void
    {
        $sql = match ($driver->name()) {
            'sqlite' => 'INSERT OR IGNORE INTO types (slug, title, default_expiry_days, default_threshold_days, default_weight_g, sort_order) VALUES (?, ?, ?, ?, ?, ?)',
            'mysql'  => 'INSERT IGNORE INTO types (slug, title, default_expiry_days, default_threshold_days, default_weight_g, sort_order) VALUES (?, ?, ?, ?, ?, ?)',
            default  => 'INSERT INTO types (slug, title, default_expiry_days, default_threshold_days, default_weight_g, sort_order) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (slug) DO NOTHING',
        };

        foreach (TypeDefaults::all() as $order => $type) {
            $driver->execute($sql, [
                $type['slug'],
                $type['title'],
                $type['expiry_days'],
                $type['threshold_days'],
                $type['weight_g'],
                $order,
            ]);
        }
    }

    /**
     * Rebuilds the legacy `products` table (kg + is_deleted + text type)
     * into the current schema (grams + status + type_id) in one transaction.
     */
    private function rebuildSqlite(SQLiteDriver $driver): void
    {
        $columns = $driver->tableColumns('products');
        $has = static fn (string $column): bool => array_key_exists($column, $columns);

        $select = [
            'id'            => 'p.id',
            'name'          => $has('name') ? "COALESCE(NULLIF(p.name, ''), 'Без названия')" : "'Без названия'",
            'type_id'       => $has('type_id')
                ? 'p.type_id'
                : ($has('type')
                    ? "COALESCE(t.id, (SELECT id FROM types WHERE slug = 'разное'))"
                    : "(SELECT id FROM types WHERE slug = 'разное')"),
            'weight_g'      => $has('weight_g')
                ? 'p.weight_g'
                : 'MAX(1, CAST(ROUND(COALESCE(p.weight, 0) * 1000) AS INTEGER))',
            'expiry_date'   => $has('expiry_date') ? 'p.expiry_date' : "date('now')",
            'threshold_days' => $has('threshold_days') ? 'COALESCE(p.threshold_days, 7)' : '7',
            'status'        => $has('status')
                ? "COALESCE(p.status, 'active')"
                : "CASE WHEN COALESCE(p.is_deleted, 0) = 1 THEN 'disposed' ELSE 'active' END",
            'created_at'    => $has('created_at') ? "COALESCE(p.created_at, datetime('now'))" : "datetime('now')",
            'updated_at'    => $has('updated_at') ? "COALESCE(p.updated_at, datetime('now'))" : "datetime('now')",
            'closed_at'     => $has('closed_at') ? 'p.closed_at' : ($has('deleted_at') ? 'p.deleted_at' : 'NULL'),
            'expiry_days_at_close' => $has('expiry_days_at_close') ? 'p.expiry_days_at_close' : 'NULL',
        ];

        $join = $has('type') ? 'LEFT JOIN types t ON t.slug = p.type' : '';

        $driver->transaction(function (SQLiteDriver $d) use ($select, $join): void {
            $d->connector()->exec('DROP TABLE IF EXISTS products_new');
            $d->connector()->exec(SQLiteDriver::productsTableSql('products_new'));

            $columns = implode(', ', array_keys($select));
            $expressions = implode(', ', array_values($select));

            $d->connector()->exec(
                "INSERT INTO products_new ({$columns}) SELECT {$expressions} FROM products p {$join}"
            );
            $d->connector()->exec('DROP TABLE products');
            $d->connector()->exec('ALTER TABLE products_new RENAME TO products');
            $d->connector()->exec('CREATE INDEX IF NOT EXISTS idx_products_status_expiry ON products(status, expiry_date)');
            $d->connector()->exec('CREATE INDEX IF NOT EXISTS idx_products_type ON products(type_id)');
            $d->setUserVersion(self::SCHEMA_VERSION);
        });
    }
}
