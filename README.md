# FreshTracker 🍎

Домашний учёт продуктов с контролем сроков годности: что лежит в запасе, что
скоро истекает, что использовано, а что пришлось выбросить.

Приложение рассчитано на одну семью (1–4 человека) и один общий «холодильник».
Пользовательских аккаунтов нет: доступ определяется сетью и, при необходимости,
паролем на редактирование.

## Стек

- PHP 8.2+, SQLite, `symfony/yaml`
- Arris-пакеты: `arris.config`, `arris.router`, `arris.logger`, `arris.database`,
  `arris.request`, `arris.toolkit.firewall`, `arris.toolkit.cli-console`
- Frontend: vanilla JS, CSS custom properties, без сборки

## Быстрый старт

```bash
composer install
cp config.dist.yaml config.yaml
# отредактируйте config.yaml: путь к БД, сети, пароли
php bin/ft migrate
php -S 127.0.0.1:8080 -t public public/router.php
```

Откройте `http://127.0.0.1:8080/`.

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

БД, логи, `config.yaml` и `var/secret` держите вне webroot.

## Конфигурация

Все ключи необязательны — отсутствующие берутся из `FreshTracker\Config\Defaults`.
`config.yaml` и старый `freshtracker.yml` читаются, если существуют.

```yaml
app:
  theme: day            # day | night
  timezone: Europe/Moscow
  debug: false          # true — показывать текст исключений в API

database:
  driver: sqlite        # sqlite | mysql | postgresql
  path: freshtracker.sqlite
  slow_query_threshold: 0.05

access:
  local:                # сети, которым доверяем
    networks: ['127.0.0.1', '::1', '192.168.0.0/16']
    visible: true       # false — полностью скрыть приложение от этих сетей
    password: ''        # пусто — редактирование без пароля
  public:               # все остальные
    visible: always     # false — полностью скрыть приложение снаружи
    password: ''        # пароль на редактирование
    editable: never     # always | password | never

security:
  secret: ''            # HMAC для cookie; пусто — сгенерировать var/secret
  trusted_proxies: []   # доверять X-Forwarded-For только этим адресам

logging:
  path: logs
  level: info

features:
  # Сохранять недозаполненную форму продукта при переходе на страницу типов
  # (sessionStorage) и восстанавливать при возврате.
  product_drafts: false
```

### Модель доступа

| Кто | Чтение | Редактирование |
|---|---|---|
| IP из `access.local.networks` | `visible: true` | всегда; если задан `password` — после его ввода |
| Остальные (`public`) | `visible: always` (по умолчанию) | `editable: always` / `password` / `never` |

- `local` имеет приоритет над `public`. `visible: false` у local — отказ, без
  фолбэка в public; `visible: false` у public закрывает доступ всем остальным.
- Пустой пароль при `editable: password` означает «редактирование запрещено»
  (fail closed), а не «открыто».
- Пароль хранится в конфиге в открытом виде; сравнение — `hash_equals`.
- После ввода пароля выдаётся HMAC-подписанная cookie `ft_edit` на 30 дней
  (`HttpOnly`, `SameSite=Lax`, `Secure` при HTTPS).
- Запись защищена от CSRF: требуется `Content-Type: application/json`,
  проверяются `Origin` и `Sec-Fetch-Site`.
- Неудачные попытки входа ограничиваются (8 за 15 минут на IP).

## Команды

```bash
php bin/ft migrate   # создать/обновить схему
php bin/ft stats     # сводка по запасам
php bin/ft check     # проверить конфиг, БД и секрет
php bin/ft version
make test            # тесты без внешних зависимостей
```

## API

Все ответы — JSON: `{"status":"ok","data":…}` или
`{"status":"error","message":…}`.

| Метод | Путь | Назначение |
|---|---|---|
| GET | `/api/config/` | тема, права, единицы, версия |
| GET | `/api/types/` | типы продуктов (с `product_count` и `active_count`) |
| POST | `/api/types/` | создать тип (local + can_edit) |
| PUT | `/api/types/{id}/` | изменить тип (local + can_edit) |
| DELETE | `/api/types/{id}/` | удалить тип, продукты → «Разное» (local + can_edit) |
| POST | `/api/types/{id}/move` | поднять/опустить тип (local + can_edit) |
| GET | `/api/products/?status=active\|history&type=&q=` | список |
| POST | `/api/products/` | создать |
| PUT | `/api/products/{id}/` | изменить |
| POST | `/api/products/{id}/use` | «использовал» |
| POST | `/api/products/{id}/dispose` | «выкинул» |
| POST | `/api/products/{id}/restore` | вернуть в запас |
| DELETE | `/api/products/{id}/` | удалить запись |
| GET | `/api/stats/` | сводка и отходы по месяцам |
| POST | `/api/auth/login` | пароль на редактирование |
| POST | `/api/auth/logout` | сбросить cookie |

Вес передаётся в граммах (`weight_g`) либо как `weight` + `weight_unit: g|kg`.
Управление типами доступно только из `local`-сети и только при `can_edit`, даже
если снаружи разрешено редактирование продуктов. Удаление типа переносит все
его продукты (включая историю) в «Разное» и добавляет к названию префикс
`[старый тип] `; сам «Разное» удалить нельзя.
Даты — `Y-m-d`; также принимаются `d.m.Y` и `d/m/Y`.

## Интерфейс

Клик по логотипу возвращает к списку продуктов и сбрасывает фильтры. Кнопка «?»
в шапке открывает окно «О программе»: версия берётся из `app/Config/Version.php`
(через `GET /api/config/`), там же показаны режим доступа и стек.

## Типы продуктов

Страница `/types.html` доступна по шестерёнке в шапке (только `local` + `can_edit`)
и по чипу «+ тип» в форме продукта. На ней можно добавить тип,
переименовать его, изменить дефолты, изменить порядок и удалить.

Slug можно ввести вручную или сгенерировать кнопкой «Сгенерировать» — она
транслитерирует кириллицу в латиницу (`заморозка` → `zamorozka`). Slug
редактируется и при изменении типа; исключение — системный «Разное», его slug
зарезервирован. Продукты ссылаются на `type_id`, поэтому смена slug на них не
влияет, но внешние ссылки вида `?type=старый_slug` перестанут фильтровать.

## Данные

`products` хранит `weight_g` (целые граммы), `status` (`active|used|disposed`),
`closed_at` и `expiry_days_at_close` — сколько дней оставалось на момент списания.
История — это `status <> 'active'`, отдельная таблица событий не нужна.

При первом запуске старая схема (килограммы + `is_deleted`) автоматически
перестраивается: кг → граммы, `is_deleted = 1` → `disposed`, текстовый тип →
`type_id`. Версия схемы хранится в `PRAGMA user_version`.

## PHAR

`freshtracker.phar` — отдельный CLI-артефакт (`php bin/ft` внутри), собирается
`make phar`. Веб-версия запускается из исходников и никогда не подхватывает PHAR
автоматически.

## Тесты

`make test` (или `php tests/run.php`) — домен (вес, даты, статусы), матрица
доступа и миграция старой схемы на in-memory SQLite. Внешних зависимостей нет.
