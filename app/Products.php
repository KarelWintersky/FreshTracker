<?php

namespace FreshTracker;

use PDO;
use PDOException;

class Products
{
    private PDO $db;
    private array $config;

    public function __construct()
    {
        $this->config = App::$config;
        $this->db = App::$pdo;
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

    public function getProducts(): bool
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

            Response::set($products);

        } catch (PDOException $e) {
            throw new \RuntimeException('Ошибка загрузки продуктов: ' . $e->getMessage(), 500);
        }

        return true;
    }

    public function getProduct(int $id):bool
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
                Response::setError('Продукт не найден', 404);
                return false;
            }

            Response::set($this->formatProduct($product));
        } catch (PDOException $e) {
            throw new \RuntimeException('Ошибка загрузки продукта: ' . $e->getMessage(), 500);
        }

        return true;
    }

    public function createProduct():bool
    {
        $data = Request::getInputData();

        $validationErrors = Validator::validateProductData($data);
        if (!empty($validationErrors)) {
            Response::setError('Ошибка валидации: ' . implode(', ', $validationErrors), 400);
            return false;
        }

        $expiry_date = Validator::processDateInput($data['expiry_date'] ?? '');
        if (!$expiry_date) {
            Response::setError('Неверный формат даты', 400);
            return false;
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
            throw new \RuntimeException('Ошибка при создании продукта: ' . $e->getMessage(), 500);
        }
        return true;
    }

    public function updateProduct(int $id): bool
    {
        $data = Request::getInputData();

        // Проверяем существование продукта
        $checkStmt = $this->db->prepare("SELECT id FROM products WHERE id = :id AND is_deleted = 0");
        $checkStmt->execute([':id' => $id]);

        if (!$checkStmt->fetch()) {
            throw  new \RuntimeException('Продукт не найден', 404);
        }

        $validationErrors = Validator::validateProductData($data, true);
        if (!empty($validationErrors)) {
            throw new \RuntimeException('Ошибка валидации: ' . implode(', ', $validationErrors), 400);
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
            $expiry_date = Validator::processDateInput($data['expiry_date']);
            if (!$expiry_date) {
                throw new \RuntimeException('Неверный формат даты', 400);
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
            throw new \RuntimeException('Нет данных для обновления', 400);
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
            throw new \RuntimeException('Ошибка при обновлении продукта: ' . $e->getMessage(), 500);
        }

        return true;
    }

    public function deleteProduct(int $id): bool
    {
        $stmt = $this->db->prepare("
            UPDATE products 
            SET is_deleted = 1, deleted_at = CURRENT_TIMESTAMP, updated_at = CURRENT_TIMESTAMP 
            WHERE id = :id AND is_deleted = 0
        ");

        try {
            $stmt->execute([':id' => $id]);

            if ($stmt->rowCount() > 0) {
                Response::set(['message' => 'Продукт удален']);
            } else {
                throw new \RuntimeException('Продукт не найден', 404);
            }
        } catch (PDOException $e) {
            throw new \RuntimeException('Ошибка при удалении продукта: ' . $e->getMessage(), 500);
        }

        return true;
    }

}