# TODO: Go-версия FreshTracker

Статус: **не начато**. Ничего из этого файла пока не реализуется — это план.

## Решение

- Ветка: `golang`, создаётся от `master` (желательно после тега `1.0.0`).
- Версия ветки: `1.x.x/Go` (например, `1.0.0/Go`), чтобы версия сразу говорила,
  что это Go-реализация.
- README — branch-specific: в `master` описан PHP-вариант, в `golang` — Go.
- Модуль: `github.com/karelwintersky/freshtracker`. Для локальной сборки
  достаточно и `module freshtracker`; на импорты это не влияет, пока бинарник
  не импортируют другие проекты.
- Go 1.22+ (нужен `http.ServeMux` с шаблонами `GET /api/products/{id}`).
- Один бинарник: веб-сервер, роутинг, SQLite, миграции, встроенный фронтенд.
  Отдельного `bin/ft` нет: миграции выполняются при старте, а `--check` и
  `--version` — обычные флаги.

## Что должно получиться

```bash
make build          # ./freshtracker
./freshtracker --config /etc/freshtracker/freshtracker.yaml
```

Конфиг, SQLite-база, логи и `var/secret` — снаружи бинарника. Сборка без CGO,
один файл, кросс-компиляция под linux/amd64 и linux/arm64.

## Библиотеки

- HTTP: стандартный `net/http` (Go 1.22+), без фреймворка.
- SQLite: `modernc.org/sqlite` (чистый Go, без CGO). DSN с прагмами
  `journal_mode(WAL)`, `busy_timeout(5000)`, `foreign_keys(1)`.
- YAML: `gopkg.in/yaml.v3`.
- Логи: `log/slog` в файл из конфига; ротация — опционально `lumberjack`.
- Cookie/пароль: `crypto/hmac`, `crypto/sha256`, `crypto/subtle`.
- IP/CIDR: `net/netip`.
- CLI-флаги: `flag` (`--config`, `--dev`, `--version`, `--check`).

## Структура

```
cmd/freshtracker/main.go       # флаги + запуск сервера
internal/config/               # YAML, дефолты, env, валидация
internal/store/                # database/sql, репозитории, миграции
internal/domain/               # weight, expiry, status, slugify, валидация
internal/access/               # local/public, пароли, cookie, throttle, trusted proxies
internal/httpapi/              # маршруты, middleware, JSON-конверт, хендлеры
web/embed.go                   # package web; //go:embed public/index.html ...
public/                        # общий фронтенд (index.html, types.html, assets)
go.mod
makefile                       # build / test / run
README.md                      # Go-версия
```

`//go:embed` не умеет `../public` из подкаталога, поэтому embed-файл лежит в
корне модуля (пакет `web`), а не в `internal/`. На ветке `golang` удаляются
`public/api.php` и `public/router.php` — их заменяет Go-сервер. Статика
(`index.html`, `types.html`, `assets/`) остаётся общей с `master`.

## Конфиг

Тот же файл и та же схема, что в PHP-версии. Приоритет:

1. `APP_CONFIG` — путь к YAML; если файла нет, ошибка.
2. `freshtracker.yaml` в каталоге проекта/рядом с бинарником.
3. Значения по умолчанию из кода.

`APP_DATABASE` переопределяет путь к SQLite, `APP_SECRET` — секрет для cookie.
Версия в `/api/config/` берётся из строки версии Go-сборки (`1.x.x/Go`).

## Паритет

- API: те же маршруты, конверт `{status,data|message}`, коды и тексты ошибок.
- Продукты: граммы, `active/used/disposed`, `closed_at`,
  `expiry_days_at_close`, возврат из истории.
- Типы: CRUD, порядок, ручной/сгенерированный slug, транслитерация,
  зарезервированный `разное`, удаление с переносом и префиксом `[тип] `.
- Доступ: `local`/`public`, `visible`, `password`, `editable`, cookie `ft_edit`,
  CSRF (`Origin`/`Sec-Fetch-Site`, JSON Content-Type), throttling,
  `trusted_proxies`.
- Фронтенд: без изменений, отдаётся из embed; `/api/*` — маршруты, остальное —
  файлы. В dev-режиме (`--dev`) статика читается с диска.
- БД: та же схема и `PRAGMA user_version`; Go-бинарник открывает базу,
  созданную PHP-версией. Legacy-rebuild (кг → граммы, `is_deleted` → `disposed`)
  портируется и покрывается тестом на копии.

## Даты

В Go не считать `days_remaining` в SQL. Брать `app.timezone`
(`time.LoadLocation`), нормализовать дату до полуночи и считать разницу в Go.
Это убирает текущий рассинхрон между `date('now')` SQLite (UTC) и PHP-таймзоной.

## make

```make
VERSION ?= $(shell cat VERSION 2>/dev/null || echo 1.0.0/Go)
build:
	CGO_ENABLED=0 go build -trimpath \
		-ldflags "-s -w -X main.version=$(VERSION)" \
		-o freshtracker ./cmd/freshtracker
test:
	go test ./...
run:
	go run ./cmd/freshtracker --config freshtracker.yaml --dev
```

## Порядок работ

1. `git tag 1.0.0` на `master` (если ещё не сделан), `git switch -c golang`.
2. Каркас: `go.mod`, `main.go`, конфиг, `slog`, `serve` со статикой.
3. SQLite: схема, миграции, репозитории продуктов и типов.
4. Домен: граммы, даты, slug, валидация.
5. Доступ: политика, cookie, CSRF, throttle, trusted proxies.
6. API-хендлеры и паритет с фронтендом.
7. Тесты: домен, доступ, миграция, `httptest` на основные маршруты.
8. Сборка/документация: `make build`, systemd-юнит, README под Go.
9. Проверка на копии боевой SQLite, затем переключение порта/прокси.

## Оценка и риски

- Объём: ~50–70 часов, 7–10 рабочих дней; основные риски — не язык, а паритет
  (SQLite-драйвер, даты, CSRF, тексты ошибок, миграция старой базы).
- `modernc.org/sqlite` медленнее CGO-драйвера, но для домашнего приложения
  разница не важна; зато нет CGO и простая кросс-компиляция.
- Пустой список в JSON должен быть `[]`, а не `null` — фронт проверяет
  `Array.isArray`.
- Конфиг и БД не встраиваются в бинарник: пути задаются снаружи.
