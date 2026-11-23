# FreshTracker 🍎

Домашний учёт продуктов и контроль сроков годности: что лежит в запасе, что
скоро истекает, что использовано, а что пришлось выбросить.

Приложение рассчитано на одну семью и один общий «холодильник». Пользовательских
аккаунтов нет: доступ определяется сетью и, при необходимости, паролем на
редактирование.

## Возможности

- Продукты: название, тип, вес в граммах, срок годности, порог предупреждения.
- Статусы `active / used / disposed`: «Использовал», «Выкинул», «Вернуть».
- История и статистика: использовано/выброшено, граммы, доля отходов по месяцам.
- Типы продуктов: CRUD, порядок, slug вручную или кнопкой «Сгенерировать»
  (транслитерация кириллицы в латиницу).
- Удаление типа переносит все его продукты в «Разное» и добавляет к названию
  префикс `[старый тип] `; сам «Разное» удалить нельзя.
- Доступ: доверенные сети — полный доступ, остальные — только чтение, по паролю
  или с полным редактированием (`editable: always | password | never`).
- Черновик формы продукта при переходе на страницу типов — за флагом
  `features.product_drafts`.
- Темы `day`/`night`, мобильная вёрстка, окно «О программе», клик по логотипу
  возвращает к списку продуктов.

## Стек

- PHP 8.2+, SQLite, YAML (`symfony/yaml`).
- Standalone-пакеты Arris: `arris.config`, `arris.router`, `arris.logger`,
  `arris.database`, `arris.request`, `arris.toolkit.firewall`.
- Frontend: vanilla JS и CSS custom properties, без сборки.
- PHPUnit 11 — только для разработки.

Ядро `karelwintersky/arris` не используется.

## Быстрый старт

```bash
composer install
cp freshtracker.dist.yaml freshtracker.yaml
# отредактируйте freshtracker.yaml: путь к БД, сети, пароли
php -S 127.0.0.1:8080 -t public public/router.php
```

Откройте `http://127.0.0.1:8080/`. Схема БД создаётся и мигрирует автоматически
при первом запуске — отдельной команды миграции нет.

Для продакшена docroot должен указывать на `public/`, а не на корень проекта.
Пример nginx:

```nginx
server {
    listen 80;
    server_name fresh.example.com;
    root /var/www/freshtracker/public;
    index index.html;

    location /api/ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root/api.php;
        fastcgi_pass unix:/run/php/php8.2-fpm.sock;
    }

    location ~* \.(sqlite|sqlite-wal|sqlite-shm|phar)$ { return 404; }
    location ~ /\. { return 404; }
}
```

Конфиг, БД, логи и `var/secret` держите вне webroot — например, в
`/etc/freshtracker/` и `/var/lib/freshtracker/`.

## PHAR и минимальный деплой

`make phar` собирает `freshtracker.phar` из `app/` и `vendor/` через Docker +
Box (`build.sh`, конфиг `box.json`). Box и его зависимости (PHP 8.3, sodium)
живут внутри контейнера — локальный PHP не важен. Если `box.phar` лежит в корне
проекта, он копируется в образ, иначе скачивается Box 4.5.0.

Без Docker: `make phar-local` — встроенный сборщик `build-phar-local.php`
(`phar.readonly=0` передаётся автоматически). На время обеих сборок ставится
`--no-dev`, после — dev-зависимости восстанавливаются.

На проде достаточно:

```
/var/www/freshtracker/
├── public/              # docroot: index.html, types.html, assets
├── freshtracker.phar    # app/ + vendor/
├── freshtracker.yaml
└── freshtracker.sqlite
```

`public/api.php` подключает `vendor/autoload.php`, если он есть, иначе
`freshtracker.phar`. В git-чекауте всегда работает исходник; на минимальном
деплое — только phar. Если лежат оба, приоритет у исходников, чтобы устаревшая
сборка не перекрывала живой код.

## Конфигурация

Приоритет:

1. `APP_CONFIG` — путь к YAML-файлу; если задан и файла нет, приложение
   завершится с ошибкой.
2. `freshtracker.yaml` в корне проекта.
3. Встроенные значения из `FreshTracker\Config\Defaults`.

`config.yaml` и `freshtracker.yml` больше не поддерживаются. `APP_DATABASE`
переопределяет путь к SQLite, `APP_SECRET` — HMAC-секрет для cookie.

Шаблон — `freshtracker.dist.yaml`:

```yaml
app:
  name: FreshTracker
  theme: day            # day | night
  timezone: UTC         # даты в БД сравниваются в UTC
  debug: false

database:
  driver: sqlite
  path: freshtracker.sqlite
  slow_query_threshold: 0.05

access:
  local:
    networks: ['127.0.0.1', '::1', '192.168.0.0/16']
    visible: true
    password: ''        # пусто — редактирование без пароля
  public:
    visible: always     # false — полностью скрыть снаружи
    password: ''
    editable: never     # always | password | never

security:
  secret: ''            # пусто — сгенерировать var/secret
  trusted_proxies: []   # доверять X-Forwarded-For только этим адресам

logging:
  path: logs
  level: info

features:
  product_drafts: false # черновик формы продукта в sessionStorage

defaults:
  threshold_days: 7

validation:
  min_weight_g: 1
  max_weight_g: 5000000
  max_threshold_days: 365
  max_name_length: 255
```

Относительные пути (`database.path`, `logging.path`) считаются от корня проекта.
Ключ со значением `null` удаляет соответствующее значение по умолчанию.

### Модель доступа

| Кто | Чтение | Редактирование |
|---|---|---|
| IP из `access.local.networks` | если `visible: true` | всегда; если задан `password` — после его ввода |
| Остальные (`public`) | `visible: always` (по умолчанию) | `editable: always` / `password` / `never` |

- `local` имеет приоритет над `public`. `visible: false` у local — отказ, без
  фолбэка в public; `visible: false` у public закрывает доступ всем остальным.
- Пустой пароль при `editable: password` означает «редактирование запрещено»
  (fail closed), а не «открыто».
- Пароль хранится в конфиге в открытом виде; сравнение — `hash_equals`.
- После ввода пароля выдаётся HMAC-подписанная cookie `ft_edit` на 30 дней
  (`HttpOnly`, `SameSite=Lax`, `Secure` при HTTPS).
- Кнопка с ключиком в шапке появляется только когда пароль задан и
  редактирование ещё не разблокировано.
- Запись защищена от CSRF: требуется `Content-Type: application/json`,
  проверяются `Origin` и `Sec-Fetch-Site`.
- Неудачные попытки входа ограничиваются (8 за 15 минут на IP).
- `X-Forwarded-For` учитывается только если непосредственный пир входит в
  `security.trusted_proxies`.

## API

Все ответы — JSON: `{"status":"ok","data":…}` или
`{"status":"error","message":…}`. Коды: 200/201/400/401/403/404/409/415/422/429/500.

| Метод | Путь | Назначение |
|---|---|---|
| GET | `/api/config/` | тема, права, версия, единицы, флаги |
| GET | `/api/types/` | типы с `product_count` и `active_count` |
| POST | `/api/types/` | создать тип (local + can_edit) |
| PUT | `/api/types/{id}/` | изменить тип (local + can_edit) |
| DELETE | `/api/types/{id}/` | удалить тип, продукты → «Разное» (local + can_edit) |
| POST | `/api/types/{id}/move` | поднять/опустить тип (local + can_edit) |
| GET | `/api/products/?status=active\|history&type=&q=` | список |
| POST | `/api/products/` | создать продукт |
| GET | `/api/products/{id}/` | один продукт |
| PUT | `/api/products/{id}/` | изменить продукт |
| DELETE | `/api/products/{id}/` | удалить запись |
| POST | `/api/products/{id}/use` | «использовал» |
| POST | `/api/products/{id}/dispose` | «выкинул» |
| POST | `/api/products/{id}/restore` | вернуть в запас |
| GET | `/api/stats/` | сводка и отходы по месяцам |
| POST | `/api/auth/login` | пароль на редактирование |
| POST | `/api/auth/logout` | сбросить cookie |

Вес передаётся в граммах (`weight_g`) либо как `weight` + `weight_unit: g|kg`.
Даты — `Y-m-d`; также принимаются `d.m.Y`, `d/m/Y` и `d-m-Y`.

Управление типами доступно только из `local`-сети и только при `can_edit`, даже
если снаружи разрешено редактирование продуктов.

## Типы и slug

- Slug генерируется из названия (транслитерация: `заморозка` → `zamorozka`) или
  вводится вручную; приводится к нижнему регистру, недопустимые символы
  заменяются на `_`, максимум 64 символа.
- Slug уникален и редактируется; системный slug `разное` зарезервирован.
- Продукты ссылаются на `type_id`, поэтому переименование и смена slug на них
  не влияет. Внешние ссылки вида `?type=старый_slug` перестанут фильтровать.
- Удаление типа переносит **все** его продукты, включая историю, в «Разное» и
  добавляет префикс `[Название типа] ` (если его ещё нет).

## Данные

`products` хранит `weight_g` (целые граммы), `status` (`active|used|disposed`),
`closed_at` и `expiry_days_at_close` — сколько дней оставалось на момент
списания. История — это `status <> 'active'`, отдельная таблица событий не нужна.

Типы и пороги по умолчанию лежат в `types`; `defaults.threshold_days` — общий
фолбэк. Версия схемы хранится в `PRAGMA user_version`.

При первом запуске старая схема (килограммы + `is_deleted`) автоматически
перестраивается: кг → граммы, `is_deleted = 1` → `disposed`, текстовый тип →
`type_id`. Перед первым запуском на боевой БД сделайте резервную копию.

## Интерфейс

- Клик по логотипу возвращает к списку продуктов и сбрасывает фильтры.
- Кнопка «?» открывает «О программе»: версия берётся из `app/Config/Version.php`
  через `GET /api/config/`.
- Кнопка-шестерёнка ведёт на `/types.html` (только `local` + `can_edit`).
- Чип «+ тип» в форме продукта ведёт туда же.
- Темы переключаются кнопкой в шапке и запоминаются в `localStorage`.

## Тесты

```bash
composer install
make test                  # PHPUnit 11
make test-legacy           # старый раннер без dev-зависимостей
```

PHPUnit-набор: 34 теста — вес, даты, статусы, матрица доступа (local/public,
пароли, IPv6), CRUD типов, миграция старой схемы и идемпотентность. Можно
запускать точечно: `vendor/bin/phpunit --filter TypeManager`.

`tests/legacy_tests.php` — прежний самописный раннер на 33 теста; оставлен как
независимый от Composer-dev референс и для быстрой проверки без PHPUnit.

## Версия и ветки

Текущая версия — `0.4.16`. Источник версии — `app/Config/Version.php`; она же
показывается в «О программе». Релизные теги — по мере готовности.

Разработка Go-версии (один бинарник: веб-сервер, SQLite, роутинг, встроенный
фронтенд) ведётся в отдельной ветке `golang`. План и ограничения —
в [TODO_GOLANG.md](TODO_GOLANG.md). Версия Go-ветки — `1.x.x/Go`, README там
свой, под Go.

## Лицензия

MIT.
