# AGENTS.md

## FreshTracker — Текущее состояние

PHP 8.0+ веб-приложение для учета продуктов и контроля сроков годности. SQLite3, vanilla JS фронтенд, Arris Router.

### Структура проекта

```
freshtracker/
├── app/
│   ├── App.php          — Инициализация (PDO, конфиг)
│   ├── Config.php       — Мерж конфигурации с дефолтами
│   ├── Database.php     — Создание таблиц SQLite
│   ├── Products.php     — CRUD операции с продуктами
│   ├── Request.php      — Парсинг JSON из тела запроса
│   ├── Response.php     — Формирование JSON-ответа
│   └── Validator.php    — Валидация данных продукта
├── public/
│   ├── api.php          — Точка входа API, роутинг
│   ├── index.html       — Фронтенд
│   └── assets/
│       ├── scripts.js   — Вся логика фронтенда
│       ├── styles.css   — Стили
│       └── flatpickr.*  — Библиотека выбора даты
├── freshtracker.yml     — Конфиг (TODO: не парсится, захардкожен в api.php)
├── freshtracker.sqlite  — База данных
└── composer.json        — Зависимости (PHP)
```

### API маршруты

| Метод | Путь | Описание |
|-------|------|----------|
| GET | `/api/products/` | Список продуктов |
| GET | `/api/products/{id}/` | Один продукт |
| POST | `/api/products/` | Создать продукт |
| PUT | `/api/products/{id}/` | Обновить продукт |
| DELETE | `/api/products/{id}/` | Удалить продукт (soft delete) |

### Схема БД

```sql
CREATE TABLE products (
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
);
```

### Что сделано (2026-07-17)

Удалена вся система авторизации:
- Удалён `app/Auth.php` и зависимость `firebase/php-jwt`
- Убраны auth-маршруты, middleware, JWT-логика из `api.php`
- Убрана auth-модалка и logout-кнопка из `index.html`
- Убраны `AuthAPI`, `AuthManager`, `addAuthStyles` из `scripts.js`
- Удалён неиспользуемый `public/assets/s.js`
- Очищены auth-CSS из `styles.css`
- Очищен `Config.php` (убрана `sendConfig()`, неиспользуемый `use PDO`)
- Исправлен баг: дублирующийся `Response::setError` в catch-блоке `api.php`
- Исправлен баг: пробел в `sendCORS` в маршруте OPTIONS

### Известные проблемы

1. **Конфиг не парсится** — `freshtracker.yml` существует, но `api.php` хардкодит все значения. YAML-файл не используется.
2. **Смешанный подход к ошибкам** — `createProduct()` использует `Response::setError`, а `updateProduct()` бросает `RuntimeException`.
3. **Нет тестов.**
4. **Нет CSRF и rate limiting** (не критично для self-hosted).

### Как запускать

```bash
composer install
# Веб-сервер (Nginx/Apache) с PHP 8.0+ и расширением sqlite3
# Точка входа: public/api.php для API, public/index.html для фронтенда
```
