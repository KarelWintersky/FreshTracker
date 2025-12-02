<?php

namespace FreshTracker;

use JetBrains\PhpStorm\NoReturn;

class API
{
    private \PDO $pdo;

    public function __construct(array $config)
    {
        $db = new Database($config);
        $db->initializeDatabase();
        App::$pdo = $this->pdo = $db->getConnection();
    }

    public function getIdFromQuery(): ?int
    {
        $id = $_GET['id'] ?? null;

        if ($id && is_numeric($id)) {
            return (int)$id;
        }

        return null;
    }

    public function createProduct():bool
    {
        return (new Products())->createProduct();
    }

    public function getProducts():bool
    {
        return (new Products())->getProducts();
    }

    public function getProduct(int $id):bool
    {
        return (new Products())->getProduct($id);
    }

    public function updateProduct($id):bool
    {
        return (new Products())->updateProduct($id);
    }

    public function deleteProduct($id):bool
    {
        return (new Products())->deleteProduct($id);
    }

    public function sendJsonError($message = '', $code = 500):bool
    {
        Response::setError($message, $code);
        return true;
    }

    public function handleCORS(): void
    {
        Response::sendCORS();
    }


}