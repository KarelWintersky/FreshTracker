# AGENTS.md — FreshTracker (karelwintersky/freshtracker)

Technical reference for the agent. Keeps the state of the code, not a changelog.

## Overview

- Household food inventory: one shared stock, expiry control, used/disposed history.
- PHP 8.2+, SQLite, YAML config, vanilla JS (no build step).
- Not multi-user: access is decided by network (`local`) or by a password gate
  (`public`) with `editable: always | password | never`.
- Entry points: `public/api.php` (JSON API), `public/index.html` (SPA).
- Namespace `FreshTracker\` → `app/` (PSR-4).

## Dependencies (composer)

Standalone Arris packages, **no `karelwintersky/arris` core**:

`arris.config`, `arris.router`, `arris.logger`, `arris.database`, `arris.request`,
`arris.toolkit.firewall`, plus `symfony/yaml` and `psr/log`.
`require-dev`: `phpunit/phpunit ^11.5`.

Integration notes:
- `Arris\Database\Connector` is lazy and PDO-compatible; `app/Database/*` is a
  micro-DBAL on top of it (dialect SQL + schema). SQLite PRAGMAs are set in
  `SQLiteDriver::configure()` — the Arris driver does not set WAL itself.
- `Arris\Toolkit\FireWall` handles IPv4 ranges/CIDR only; IPv6 is implemented in
  `AccessPolicy` (exact + CIDR).
- `Arris\Request` reads `$_REQUEST`; JSON bodies are decoded by `Http\Input::json()`.
- `AppRouter`: use named args for `group(callback: …)`; route params arrive as
  named arguments (`{id}` → `$id`, declared as `string` and validated).

## Structure

```
app/
  Bootstrap/App.php            config → logger → DB → migrator → secret/session → policy
  Config/Defaults.php          default config tree
  Config/TypeDefaults.php      seed product types
  Config/Version.php           VERSION constant (bumped per release)
  AppDatabase.php              facade over a DatabaseDriver
  Database/                    micro-DBAL: DatabaseDriver + SQLite/MySQL/PostgreSQL
  Migrations/Migrator.php      schema v1 + legacy rebuild (kg→g, is_deleted→status)
  Domain/                      Weight, Expiry, ProductStatus, ProductValidator, TypeManager
  Repositories/                ProductRepository, TypeRepository
  Access/                      AccessPolicy, AccessContext, EditSession, ClientIp, Throttle
  Http/                        Router, Response, Input, JsonGuard, HttpException
  Controllers/                 Config, Types, Products, Stats, Auth
  Support/Secret.php           HMAC secret (env → config → var/secret)
public/
  api.php                      front controller (boot, ACL, CSRF guard, dispatch)
  router.php                   dev router for `php -S`
  index.html                   SPA shell
  types.html                   type-management page (local editors only)
  assets/{styles,themes}.css   layout + colour tokens (day/night)
  assets/{theme,api,app}.js    theme pre-paint, fetch wrapper, UI
  assets/types.js              type CRUD page
tests/                         PHPUnit suite (+ Support/DatabaseTestCase)
tests/legacy_tests.php         dependency-free legacy runner
phpunit.xml                    PHPUnit config (tests/, source app/)
freshtracker.dist.yaml         documented config template
box.json                       Box config (app + vendor, no public/)
build.sh                       Docker + Box PHAR builder (make phar)
build-phar-local.php           dependency-free PHAR builder (make phar-local)
```

## Config

Config priority: `APP_CONFIG` (must exist) → `freshtracker.yaml` in the project
root → `Config\Defaults`. `APP_DATABASE` overrides the SQLite path and
`APP_SECRET` overrides the cookie secret. `config.yaml` and `freshtracker.yml`
are no longer read.

Sections: `app` (theme, timezone, debug), `database` (driver/path/slow query),
`access.local` (networks, visible, password), `access.public` (visible, password,
editable), `security` (secret, trusted_proxies), `logging`, `defaults`,
`validation`, `features` (`product_drafts`).

Access resolution (`Access\AccessPolicy::context`):
- IP in `local.networks` → group `local`; `visible: false` denies everything;
  edit allowed when `password` is empty or the `ft_edit` cookie grants `local`.
- Otherwise group `public`; `visible: false` denies everything (safety valve);
  otherwise read is always allowed; edit per `editable`:
  `always` → yes; `password` → only with grant (empty password = fail closed);
  `never` → no.
- Passwords are plaintext in config, compared with `hash_equals`.
- `EditSession`: HMAC-SHA256 cookie `ft_edit` (scope + expiry), 30 days,
  HttpOnly/SameSite=Lax/Secure on HTTPS.
- `ClientIp`: uses `REMOTE_ADDR`; `X-Forwarded-For` only when the peer is in
  `security.trusted_proxies`.

## Database

`PRAGMA user_version = 1`. SQLite schema:

```sql
types (
  id, slug UNIQUE, title,
  default_expiry_days, default_threshold_days, default_weight_g, sort_order
)
products (
  id, name, type_id → types,
  weight_g INTEGER CHECK (weight_g > 0),
  expiry_date TEXT (Y-m-d),
  threshold_days, status TEXT CHECK (active|used|disposed),
  created_at, updated_at, closed_at, expiry_days_at_close
)
login_attempts (id, ip, attempted_at)
```

- `days_remaining` = `julianday(date(expiry)) - julianday(date('now'))`, so the
  expiry date itself is `0` (not negative).
- History = `status <> 'active'`; no event table. `expiry_days_at_close` is a
  snapshot for waste analytics.
- `Migrator::ensure()` runs on every boot; it is idempotent and there is no separate CLI.
  Legacy DBs are rebuilt in one transaction (only `types`/`login_attempts` are
  created before the rebuild — product indexes reference `status`).

## API

JSON envelope: `{"status":"ok","data":…}` / `{"status":"error","message":…}`.
Status codes: 200/201/400/401/403/404/409/415/422/429/500.

| Method | Path |
|---|---|
| GET | `/api/config/` |
| GET | `/api/types/` (with `product_count` / `active_count`) |
| POST | `/api/types/` (local + can_edit) |
| PUT | `/api/types/{id}/` (local + can_edit) |
| DELETE | `/api/types/{id}/` (local + can_edit) |
| POST | `/api/types/{id}/move` (local + can_edit) |
| GET | `/api/products/?status=active\|history&type=&q=` |
| POST | `/api/products/` |
| GET/PUT/DELETE | `/api/products/{id}/` |
| POST | `/api/products/{id}/use` |
| POST | `/api/products/{id}/dispose` |
| POST | `/api/products/{id}/restore` |
| GET | `/api/stats/` |
| POST | `/api/auth/login` / `/api/auth/logout` |

Weight input: `weight_g`, or `weight` + `weight_unit: g|kg`. Dates: `Y-m-d`,
`d.m.Y`, `d/m/Y`, `d-m-Y`.

Writes require `Content-Type: application/json` and pass `Http\JsonGuard`
(Origin / Sec-Fetch-Site), which also blocks the classic `text/plain` CSRF.

## Frontend

- `theme.js` runs synchronously in `<head>` (no FOUC); theme choice in
  `localStorage['ft-theme']`, default from `app.theme`.
- `api.js` wraps fetch, unwraps the envelope, attaches HTTP status to `Error`.
- `app.js`: dashboard (summary cards, grouped list), history tab, bottom-sheet
  form, password modal, toasts. Event delegation only, no inline handlers.
- Weight is stored in grams; the form switches g/kg automatically.
- Actions: «Использовал» / «Выкинул»; contextual primary button by status.
- Type management lives on `/types.html`; the gear in the header and the
  «+ тип» chip in the product form is shown only for `local` + `can_edit`. Deleting a type moves
  all its products to the system type `разное` and prefixes names with
  `[тип] `; `разное` is not deletable. Slugs are editable (manual or generated
  with Cyrillic→Latin transliteration); the system slug `разное` is reserved.
- `features.product_drafts` keeps an unfinished product form in `sessionStorage`
  across navigation to `/types.html`.
- Clicking the brand returns to the product list (resets filters on the SPA);
  the «?» button opens the About modal (`assets/about.js`), whose version comes
  from `Config\Version` via `GET /api/config/`.

## Tests

```bash
make test          # PHPUnit 11 (vendor/bin/phpunit)
make test-legacy   # tests/legacy_tests.php, no dev dependencies
```

PHPUnit classes: `WeightTest`, `ExpiryTest`, `ProductStatusTest`,
`AccessPolicyTest`, `TypeManagerTest`, `MigrationTest`; shared in-memory DB setup
in `Tests\Support\DatabaseTestCase`. Covers the same behaviors as the legacy
runner (34 vs 33 tests) plus PHPUnit reporting and filters.

## PHAR (web)

There is no separate CLI. Migrations run on boot.

`make phar` builds `freshtracker.phar` from `app/` + `vendor/` via Docker + Box
(`build.sh`, `box.json`); the container provides PHP 8.3 and sodium. `make
phar-local` is the dependency-free fallback (`build-phar-local.php`,
`phar.readonly=0`). Both install `--no-dev` for the build and restore dev deps
afterwards. A minimal production deploy needs only `public/`, the PHAR,
`freshtracker.yaml` and the SQLite file.
`public/api.php` prefers `vendor/autoload.php` when present and falls back to the
PHAR otherwise, so a stale PHAR never shadows a source checkout. A Go single-binary
port is planned in `TODO_GOLANG.md`.

## Status / TODO

- No user accounts by design; a grant is a single shared password per scope.
- MySQL/PostgreSQL drivers are best-effort and not covered by tests.
- `public/_version` is a build artifact; source of truth is `Config\Version`.
- Possible next steps: per-type thresholds in the UI, weight units beyond g/kg,
  CSV export of the history.
- Go port plan: `TODO_GOLANG.md`; branch `golang`, version `1.x.x/Go`,
  branch-specific README.

## History

- 2026-07-17 — JWT auth removed.
- 2026-07-21 — IP-based access control (`admin`/`view`).
- 0.4.0 — rewrite: standalone Arris packages, DBAL over arris.database, grams,
  used/disposed statuses, history/stats, day/night themes, password gate,
  CLI, tests, PHAR as a separate CLI artifact.
- 0.4.13 — config is `freshtracker.yaml` (`APP_CONFIG` → file → defaults);
  CLI (`bin/ft`), the CLI-only PHAR and the `cli-console` dependency removed;
  README rewritten; `TODO_GOLANG.md` added.
- 0.4.14 — PHPUnit 11 added; tests converted to classes; `tests/run.php`
  preserved as `tests/legacy_tests.php`.
- 0.4.15 — web PHAR restored (`box.json`, `make phar`, `api.php` fallback
  to `freshtracker.phar` when `vendor/` is absent).
- 0.4.16 — local PHAR builder moved to `build-phar-local.php` (root); paths updated.
