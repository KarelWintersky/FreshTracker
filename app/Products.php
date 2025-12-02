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

    public function getProducts(): void
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

            // $this->sendJsonResponse($products);
        } catch (PDOException $e) {
            throw new \RuntimeException('Ошибка загрузки продуктов: ' . $e->getMessage(), 500);
        }
    }

    public function getProduct(int $id): void
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
                return;
            }

            Response::set($this->formatProduct($product));
        } catch (PDOException $e) {
            throw new \RuntimeException('Ошибка загрузки продукта: ' . $e->getMessage(), 500);
        }
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

    private function _createProduct(): void
    {


    }

}