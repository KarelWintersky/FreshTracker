<?php

namespace FreshTracker;

use JetBrains\PhpStorm\NoReturn;

class API
{
    private \PDO $pdo;
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;

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

    public function createProduct()
    {
        (new Products())->createProduct();
    }

    public function getProducts()
    {
        (new Products())->getProducts();
    }





    #[NoReturn]
    public function sendJsonError($message = '', $code = 500):void
    {
        $this->sendJsonResponse([
            'error' => true,
            'message' => $message
        ], $code);
    }

    #[NoReturn]
    public function sendJsonResponse($data, int $statusCode = 200): void
    {
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
        http_response_code($statusCode);
        echo json_encode($data, JSON_NUMERIC_CHECK | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit; //@todo ???
    }

    #[NoReturn]
    public function handleCORS(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization');
        header('Content-Type: application/json');
        http_response_code(200);
        exit;
    }

}