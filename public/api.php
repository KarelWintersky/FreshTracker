<?php
declare(strict_types=1);

class FreshTrackerAPI
{
    private PDO $db;
    private array $config;

    public function __construct(array $config = [])
    {
        $this->config = $this->mergeWithDefaults($config);
        $this->initializeDatabase();
    }

    private function mergeWithDefaults(array $config): array
    {
        $defaults = [
            'database' => [
                'path' => 'freshtracker.sqlite',
                'options' => [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            ],
            'defaults' => [
                'threshold_days' => 7,
                'type' => 'разное'
            ],
            'validation' => [
                'max_weight' => 1000,
                'max_threshold_days' => 365,
                'min_weight' => 0.001
            ]
        ];

        return array_replace_recursive($defaults, $config);
    }

    private function initializeDatabase(): void
    {
        try {
            $dbConfig = $this->config['database'];
            $this->db = new PDO('sqlite:' . $dbConfig['path'], options: $dbConfig['options']);
            $this->createTables();
        } catch (PDOException $e) {
            $this->sendJsonError('Ошибка инициализации базы данных: ' . $e->getMessage(), 500);
        }
    }

    private function createTables(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS products (
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
            )
        ");

        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_expiry_date ON products(expiry_date)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_type ON products(type)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_is_deleted ON products(is_deleted)");
    }

    public function handleRequest(): void
    {
        try {
            $method = $_SERVER['REQUEST_METHOD'];
            $id = $this->getIdFromQuery();

            match ($method) {
                'GET' => $id ? $this->getProduct($id) : $this->getProducts(),
                'POST' => $this->createProduct(),
                'PUT' => $id ? $this->updateProduct($id) : $this->sendJsonError('ID продукта не указан', 400),
                'DELETE' => $id ? $this->softDeleteProduct($id) : $this->sendJsonError('ID продукта не указан', 400),
                'OPTIONS' => $this->handleCors(),
                default => $this->sendJsonError('Метод не поддерживается', 405)
            };
        } catch (Throwable $e) {
            $this->sendJsonError('Внутренняя ошибка сервера: ' . $e->getMessage(), 500);
        }
    }

    private function getIdFromQuery(): ?int
    {
        $id = $_GET['id'] ?? null;

        if ($id && is_numeric($id)) {
            return (int)$id;
        }

        return null;
    }

    private function getInputData(): array
    {
        $input = file_get_contents('php://input');

        if (empty($input)) {
            return [];
        }

        $data = json_decode($input, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->sendJsonError('Неверный формат JSON', 400);
        }

        return $data ?? [];
    }

    private function getProducts(): void
    {
        try {
            $stmt = $this->db->query("
                SELECT *, 
                       julianday(expiry_date) - julianday('now') as days_remaining
                FROM products 
                WHERE is_deleted = 0
                ORDER BY expiry_date ASC
            ");

            $products = $stmt->fetchAll();

            $products = array_map(function ($product) {
                return $this->formatProduct($product);
            }, $products);

            $this->sendJsonResponse($products);
        } catch (PDOException $e) {
            $this->sendJsonError('Ошибка загрузки продуктов: ' . $e->getMessage(), 500);
        }
    }

    private function getProduct(int $id): void
    {
        try {
            $stmt = $this->db->prepare("
                SELECT *, 
                       julianday(expiry_date) - julianday('now') as days_remaining
                FROM products 
                WHERE id = :id AND is_deleted = 0
            ");

            $stmt->execute([':id' => $id]);
            $product = $stmt->fetch();

            if (!$product) {
                $this->sendJsonError('Продукт не найден', 404);
                return;
            }

            $this->sendJsonResponse($this->formatProduct($product));
        } catch (PDOException $e) {
            $this->sendJsonError('Ошибка загрузки продукта: ' . $e->getMessage(), 500);
        }
    }

    private function createProduct(): void
    {
        $data = $this->getInputData();

        $validationErrors = $this->validateProductData($data);
        if (!empty($validationErrors)) {
            $this->sendJsonError('Ошибка валидации: ' . implode(', ', $validationErrors), 400);
        }

        $expiry_date = $this->processDateInput($data['expiry_date'] ?? '');
        if (!$expiry_date) {
            $this->sendJsonError('Неверный формат даты', 400);
        }

        $stmt = $this->db->prepare("
            INSERT INTO products (name, weight, expiry_date, type, threshold_days) 
            VALUES (:name, :weight, :expiry_date, :type, :threshold_days)
        ");

        try {
            $stmt->execute([
                ':name' => $data['name'],
                ':weight' => (float)$data['weight'],
                ':expiry_date' => $expiry_date,
                ':type' => $data['type'] ?? $this->config['defaults']['type'],
                ':threshold_days' => (int)($data['threshold_days'] ?? $this->config['defaults']['threshold_days'])
            ]);

            $productId = $this->db->lastInsertId();
            $this->getProduct((int)$productId);
        } catch (PDOException $e) {
            $this->sendJsonError('Ошибка при создании продукта: ' . $e->getMessage(), 500);
        }
    }

    private function updateProduct(int $id): void
    {
        $data = $this->getInputData();

        // Проверяем существование продукта
        $checkStmt = $this->db->prepare("SELECT id FROM products WHERE id = :id AND is_deleted = 0");
        $checkStmt->execute([':id' => $id]);

        if (!$checkStmt->fetch()) {
            $this->sendJsonError('Продукт не найден', 404);
        }

        $validationErrors = $this->validateProductData($data, true);
        if (!empty($validationErrors)) {
            $this->sendJsonError('Ошибка валидации: ' . implode(', ', $validationErrors), 400);
        }

        // Строим динамический UPDATE запрос
        $fields = [];
        $params = [':id' => $id];

        if (isset($data['name'])) {
            $fields[] = 'name = :name';
            $params[':name'] = $data['name'];
        }

        if (isset($data['weight'])) {
            $fields[] = 'weight = :weight';
            $params[':weight'] = (float)$data['weight'];
        }

        if (isset($data['expiry_date'])) {
            $expiry_date = $this->processDateInput($data['expiry_date']);
            if (!$expiry_date) {
                $this->sendJsonError('Неверный формат даты', 400);
            }
            $fields[] = 'expiry_date = :expiry_date';
            $params[':expiry_date'] = $expiry_date;
        }

        if (isset($data['type'])) {
            $fields[] = 'type = :type';
            $params[':type'] = $data['type'];
        }

        if (isset($data['threshold_days'])) {
            $fields[] = 'threshold_days = :threshold_days';
            $params[':threshold_days'] = (int)$data['threshold_days'];
        }

        $fields[] = 'updated_at = CURRENT_TIMESTAMP';

        if (empty($fields)) {
            $this->sendJsonError('Нет данных для обновления', 400);
        }

        $stmt = $this->db->prepare("
            UPDATE products 
            SET " . implode(', ', $fields) . "
            WHERE id = :id AND is_deleted = 0
        ");

        try {
            $stmt->execute($params);
            $this->getProduct($id);
        } catch (PDOException $e) {
            $this->sendJsonError('Ошибка при обновлении продукта: ' . $e->getMessage(), 500);
        }
    }

    private function softDeleteProduct(int $id): void
    {
        $stmt = $this->db->prepare("
            UPDATE products 
            SET is_deleted = 1, deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP 
            WHERE id = :id AND is_deleted = 0
        ");

        try {
            $stmt->execute([':id' => $id]);

            if ($stmt->rowCount() > 0) {
                $this->sendJsonResponse(['message' => 'Продукт успешно удален']);
            } else {
                $this->sendJsonError('Продукт не найден', 404);
            }
        } catch (PDOException $e) {
            $this->sendJsonError('Ошибка при удалении продукта: ' . $e->getMessage(), 500);
        }
    }

    private function validateProductData(array $data, bool $isUpdate = false): array
    {
        $errors = [];
        $validation = $this->config['validation'];

        if (!$isUpdate || isset($data['name'])) {
            $name = $data['name'] ?? '';
            if (empty($name)) {
                $errors[] = 'Название продукта обязательно';
            } elseif (strlen($name) > 255) {
                $errors[] = 'Название продукта не должно превышать 255 символов';
            }
        }

        if (!$isUpdate || isset($data['weight'])) {
            $weight = filter_var($data['weight'] ?? null, FILTER_VALIDATE_FLOAT);
            if ($weight === false || $weight <= 0) {
                $errors[] = 'Вес должен быть положительным числом';
            } elseif ($weight < $validation['min_weight']) {
                $errors[] = sprintf('Вес должен быть не менее %s кг', $validation['min_weight']);
            } elseif ($weight > $validation['max_weight']) {
                $errors[] = sprintf('Вес не должен превышать %s кг', $validation['max_weight']);
            }
        }

        if (!$isUpdate || isset($data['threshold_days'])) {
            $threshold_days = filter_var($data['threshold_days'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => $validation['max_threshold_days']]
            ]);
            if ($threshold_days === false) {
                $errors[] = sprintf('Порог предупреждения должен быть от 1 до %s дней', $validation['max_threshold_days']);
            }
        }

        return $errors;
    }

    private function processDateInput(string $input): string|false
    {
        $input = trim($input);

        if (is_numeric($input)) {
            $days = (int)$input;
            $date = new DateTime();
            $date->modify("+{$days} days");
            return $date->format('Y-m-d');
        }

        $formats = ['Y-m-d', 'd.m.Y', 'd/m/Y', 'd-m-Y', 'Y/m/d', 'd.m.y', 'd/m/y'];

        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $input);
            if ($date && $date->format($format) === $input) {
                return $date->format('Y-m-d');
            }
        }

        return false;
    }

    private function formatProduct(array $product): array
    {
        return [
            'id' => (int)$product['id'],
            'name' => $product['name'],
            'weight' => (float)$product['weight'],
            'expiry_date' => $product['expiry_date'],
            'type' => $product['type'],
            'threshold_days' => (int)$product['threshold_days'],
            'days_remaining' => (float)($product['days_remaining'] ?? 0),
            'created_at' => $product['created_at'] ?? null,
            'updated_at' => $product['updated_at'] ?? null,
            'is_deleted' => (bool)($product['is_deleted'] ?? false),
            'deleted_at' => $product['deleted_at'] ?? null
        ];
    }

    private function handleCors(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Content-Type: application/json');
        http_response_code(200);
        exit;
    }

    private function sendJsonResponse($data, int $statusCode = 200): void
    {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        http_response_code($statusCode);
        echo json_encode($data, JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    private function sendJsonError(string $message, int $statusCode = 400): void
    {
        $this->sendJsonResponse([
            'error' => true,
            'message' => $message
        ], $statusCode);
    }
}

// Запуск API
try {
    $config = [
        'database' => [
            'path' => 'freshtracker.sqlite',
            'options' => [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ]
        ],
        'defaults' => [
            'threshold_days' => 7,
            'type' => 'разное'
        ],
        'validation' => [
            'max_weight' => 1000,
            'max_threshold_days' => 365,
            'min_weight' => 0.001
        ]
    ];

    $api = new FreshTrackerAPI($config);
    $api->handleRequest();
} catch (Throwable $e) {
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    http_response_code(500);
    echo json_encode([
        'error' => true,
        'message' => 'Внутренняя ошибка сервера: ' . $e->getMessage()
    ]);
}
