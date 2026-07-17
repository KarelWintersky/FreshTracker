<?php

namespace FreshTracker\Controllers;

use FreshTracker\App;
use FreshTracker\AppDatabase;
use FreshTracker\Units\Request;
use FreshTracker\Units\Validator;
use PDOException;

class ProductsController
{
    private AppDatabase $db;
    private array $config;

    public function __construct()
    {
        $this->config = App::$config;
        $this->db = App::$db;
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
            $daysExpr = $this->db->sqlDaysRemaining('expiry_date');
            $products = $this->db->fetchAll("
                SELECT *, 
                       {$daysExpr} as days_remaining
                FROM products 
                WHERE is_deleted = 0
                ORDER BY expiry_date ASC
            ");

            $products = array_map(function ($product) {
                return $this->formatProduct($product);
            }, $products);

            ResponseController::set($products);

        } catch (PDOException $e) {
            throw new \RuntimeException('Ошибка загрузки продуктов: ' . $e->getMessage(), 500);
        }

        return true;
    }

    public function getProduct(int $id):bool
    {
        try {
            $daysExpr = $this->db->sqlDaysRemaining('expiry_date');
            $product = $this->db->fetchOne("
                SELECT *, 
                       {$daysExpr} as days_remaining
                FROM products 
                WHERE id = ? AND is_deleted = 0
            ", [$id]);

            if (!$product) {
                ResponseController::setError('Продукт не найден', 404);
                return false;
            }

            ResponseController::set($this->formatProduct($product));
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
            ResponseController::setError('Ошибка валидации: ' . implode(', ', $validationErrors), 400);
            return false;
        }

        $expiry_date = Validator::processDateInput($data['expiry_date'] ?? '');
        if (!$expiry_date) {
            ResponseController::setError('Неверный формат даты', 400);
            return false;
        }

        try {
            $productId = $this->db->insert("
                INSERT INTO products (name, weight, expiry_date, type, threshold_days) 
                VALUES (?, ?, ?, ?, ?)
            ", [
                $data['name'],
                (float)$data['weight'],
                $expiry_date,
                $data['type'] ?? $this->config['defaults']['type'],
                (int)($data['threshold_days'] ?? $this->config['defaults']['threshold_days'])
            ]);

            $this->getProduct((int)$productId);
        } catch (PDOException $e) {
            throw new \RuntimeException('Ошибка при создании продукта: ' . $e->getMessage(), 500);
        }
        return true;
    }

    public function updateProduct(int $id): bool
    {
        $data = Request::getInputData();

        $product = $this->db->fetchOne("SELECT id FROM products WHERE id = ? AND is_deleted = 0", [$id]);

        if (!$product) {
            throw  new \RuntimeException('Продукт не найден', 404);
        }

        $validationErrors = Validator::validateProductData($data, true);
        if (!empty($validationErrors)) {
            throw new \RuntimeException('Ошибка валидации: ' . implode(', ', $validationErrors), 400);
        }

        $fields = [];
        $params = [];

        if (isset($data['name'])) {
            $fields[] = 'name = ?';
            $params[] = $data['name'];
        }

        if (isset($data['weight'])) {
            $fields[] = 'weight = ?';
            $params[] = (float)$data['weight'];
        }

        if (isset($data['expiry_date'])) {
            $expiry_date = Validator::processDateInput($data['expiry_date']);
            if (!$expiry_date) {
                throw new \RuntimeException('Неверный формат даты', 400);
            }
            $fields[] = 'expiry_date = ?';
            $params[] = $expiry_date;
        }

        if (isset($data['type'])) {
            $fields[] = 'type = ?';
            $params[] = $data['type'];
        }

        if (isset($data['threshold_days'])) {
            $fields[] = 'threshold_days = ?';
            $params[] = (int)$data['threshold_days'];
        }

        $fields[] = 'updated_at = ' . $this->db->sqlNow();

        if (empty($fields)) {
            throw new \RuntimeException('Нет данных для обновления', 400);
        }

        $params[] = $id;

        try {
            $this->db->execute("
                UPDATE products 
                SET " . implode(', ', $fields) . "
                WHERE id = ? AND is_deleted = 0
            ", $params);

            $this->getProduct($id);
        } catch (PDOException $e) {
            throw new \RuntimeException('Ошибка при обновлении продукта: ' . $e->getMessage(), 500);
        }

        return true;
    }

    public function deleteProduct(int $id): bool
    {
        try {
            $now = $this->db->sqlNow();
            $affected = $this->db->execute("
                UPDATE products 
                SET is_deleted = 1, deleted_at = {$now}, updated_at = {$now}
                WHERE id = ? AND is_deleted = 0
            ", [$id]);

            if ($affected > 0) {
                ResponseController::set(['message' => 'Продукт удален']);
            } else {
                throw new \RuntimeException('Продукт не найден', 404);
            }
        } catch (PDOException $e) {
            throw new \RuntimeException('Ошибка при удалении продукта: ' . $e->getMessage(), 500);
        }

        return true;
    }

}