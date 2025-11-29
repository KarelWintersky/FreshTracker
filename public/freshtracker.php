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
            $this->sendError('Ошибка инициализации базы данных: ' . $e->getMessage());
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
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )
        ");

        // Создаем индекс для оптимизации запросов
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_expiry_date ON products(expiry_date)");
        $this->db->exec("CREATE INDEX IF NOT EXISTS idx_type ON products(type)");
    }

    public function handleRequest(): void
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->sendError('Метод не поддерживается');
        }

        $action = $_POST['action'] ?? null;

        match ($action) {
            'add' => $this->addProduct(),
            'delete' => $this->deleteProduct(),
            'get_list' => $this->getProductList(),
            default => $this->sendError('Неизвестное действие')
        };
    }

    private function addProduct(): void
    {
        $name = trim($_POST['name'] ?? '');
        $weight = filter_var($_POST['weight'] ?? 0, FILTER_VALIDATE_FLOAT);
        $expiry_input = $_POST['expiry_date'] ?? '';
        $type = $_POST['type'] ?? $this->config['defaults']['type'];
        $threshold_days = filter_var(
            $_POST['threshold_days'] ?? $this->config['defaults']['threshold_days'],
            FILTER_VALIDATE_INT,
            ['options' => [
                'min_range' => 1,
                'max_range' => $this->config['validation']['max_threshold_days']
            ]]
        );

        // Валидация данных с использованием конфига
        $validationErrors = $this->validateProductData($name, $weight, $threshold_days);
        if (!empty($validationErrors)) {
            $this->sendError(implode(', ', $validationErrors));
        }

        $expiry_date = $this->processDateInput($expiry_input);
        if (!$expiry_date) {
            $this->sendError('Неверный формат даты. Используйте ДД.ММ.ГГГГ или количество дней');
        }

        $stmt = $this->db->prepare("
            INSERT INTO products (name, weight, expiry_date, type, threshold_days) 
            VALUES (:name, :weight, :expiry_date, :type, :threshold_days)
        ");

        try {
            $stmt->execute([
                ':name' => $name,
                ':weight' => $weight,
                ':expiry_date' => $expiry_date,
                ':type' => $type,
                ':threshold_days' => $threshold_days
            ]);

            $this->sendSuccess('Продукт успешно добавлен');
        } catch (PDOException $e) {
            $this->sendError('Ошибка при добавлении продукта: ' . $e->getMessage());
        }
    }

    private function validateProductData(string $name, mixed $weight, mixed $threshold_days): array
    {
        $errors = [];
        $validation = $this->config['validation'];

        // Валидация названия
        if (empty($name)) {
            $errors[] = 'Название продукта обязательно';
        } elseif (strlen($name) > 255) {
            $errors[] = 'Название продукта не должно превышать 255 символов';
        }

        // Валидация веса
        if ($weight === false || $weight <= 0) {
            $errors[] = 'Вес должен быть положительным числом';
        } elseif ($weight < $validation['min_weight']) {
            $errors[] = sprintf('Вес должен быть не менее %s кг', $validation['min_weight']);
        } elseif ($weight > $validation['max_weight']) {
            $errors[] = sprintf('Вес не должен превышать %s кг', $validation['max_weight']);
        }

        // Валидация порога
        if ($threshold_days === false) {
            $errors[] = sprintf(
                'Порог предупреждения должен быть от 1 до %s дней',
                $validation['max_threshold_days']
            );
        }

        return $errors;
    }

    private function deleteProduct(): void
    {
        $id = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1]
        ]);

        if ($id === false) {
            $this->sendError('Неверный ID продукта');
        }

        $stmt = $this->db->prepare("DELETE FROM products WHERE id = :id");

        try {
            $stmt->execute([':id' => $id]);

            if ($stmt->rowCount() > 0) {
                $this->sendSuccess('Продукт успешно удален');
            } else {
                $this->sendError('Продукт не найден');
            }
        } catch (PDOException $e) {
            $this->sendError('Ошибка при удалении продукта: ' . $e->getMessage());
        }
    }

    private function getProductList(): void
    {
        try {
            $stmt = $this->db->query("
                SELECT *, 
                       julianday(expiry_date) - julianday('now') as days_remaining
                FROM products 
                ORDER BY expiry_date ASC
            ");

            $products = $stmt->fetchAll();

            // Преобразуем типы данных для клиента
            $products = array_map(function ($product) {
                return [
                    'id' => (int)$product['id'],
                    'name' => $product['name'],
                    'weight' => (float)$product['weight'],
                    'expiry_date' => $product['expiry_date'],
                    'type' => $product['type'],
                    'threshold_days' => (int)$product['threshold_days'],
                    'days_remaining' => (float)$product['days_remaining'],
                    'created_at' => $product['created_at'] ?? null
                ];
            }, $products);

            echo json_encode($products, JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT);
        } catch (PDOException $e) {
            $this->sendError('Ошибка загрузки данных: ' . $e->getMessage());
        }
    }

    private function processDateInput(string $input): string|false
    {
        $input = trim($input);

        // Если введено число - добавляем указанное количество дней к текущей дате
        if (is_numeric($input)) {
            $days = (int)$input;
            $date = new DateTime();
            $date->modify("+{$days} days");
            return $date->format('Y-m-d');
        }

        // Пробуем различные форматы дат
        $formats = [
            'Y-m-d',
            'd.m.Y',
            'd/m/Y',
            'd-m-Y',
            'Y/m/d',
            'd.m.y',
            'd/m/y'
        ];

        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $input);
            if ($date && $date->format($format) === $input) {
                return $date->format('Y-m-d');
            }
        }

        return false;
    }

    private function sendSuccess(string $message, array $data = []): void
    {
        echo json_encode([
            'success' => true,
            'message' => $message,
            ...$data
        ], JSON_NUMERIC_CHECK);
        exit;
    }

    private function sendError(string $message): void
    {
        echo json_encode([
            'success' => false,
            'message' => $message
        ]);
        exit;
    }

    // Геттер для получения текущей конфигурации (для тестирования)
    public function getConfig(): array
    {
        return $this->config;
    }
}

// Запуск приложения с конфигурацией
try {
    $config = [
        'database' => [
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
    echo json_encode([
        'success' => false,
        'message' => 'Внутренняя ошибка сервера'
    ]);
}
