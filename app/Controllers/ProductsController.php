<?php

declare(strict_types=1);

namespace FreshTracker\Controllers;

use FreshTracker\Bootstrap\App;
use FreshTracker\Domain\Expiry;
use FreshTracker\Domain\ProductStatus;
use FreshTracker\Domain\ProductValidator;
use FreshTracker\Domain\Weight;
use FreshTracker\Http\HttpException;
use FreshTracker\Http\Input;
use FreshTracker\Http\Response;
use FreshTracker\Repositories\ProductRepository;
use FreshTracker\Repositories\TypeRepository;

final class ProductsController
{
    private ProductRepository $products;
    private ProductValidator $validator;

    public function __construct()
    {
        $db = App::db();
        $this->products = new ProductRepository($db);
        $this->validator = new ProductValidator(new TypeRepository($db), [
            'min_weight_g'       => App::config('validation.min_weight_g', 1),
            'max_weight_g'       => App::config('validation.max_weight_g', 5_000_000),
            'max_threshold_days' => App::config('validation.max_threshold_days', 365),
            'max_name_length'    => App::config('validation.max_name_length', 255),
        ]);
    }

    public function index(): never
    {
        $type = Input::query('type');
        $query = Input::query('q');
        $status = Input::query('status', 'active');

        $rows = $status === 'history'
            ? $this->products->history($type !== '' ? $type : null, $query)
            : $this->products->active($type !== '' ? $type : null, $query);

        Response::ok(array_map(fn (array $row): array => $this->format($row), $rows));
    }

    public function show(string $id): never
    {
        Response::ok($this->format($this->find($this->parseId($id))));
    }

    public function store(): never
    {
        $data = Input::json();

        if (!array_key_exists('threshold_days', $data)) {
            $data['threshold_days'] = (int) App::config('defaults.threshold_days', 7);
        }

        $normalized = $this->validator->validate($data);
        $id = $this->products->create($normalized);

        Response::ok($this->format($this->find($id)), 201);
    }

    public function update(string $id): never
    {
        $productId = $this->parseId($id);
        $this->find($productId);

        $normalized = $this->validator->validate(Input::json(), true);
        $this->products->update($productId, $normalized);

        Response::ok($this->format($this->find($productId)));
    }

    public function markUsed(string $id): never
    {
        Response::ok($this->close($this->parseId($id), ProductStatus::Used));
    }

    public function dispose(string $id): never
    {
        Response::ok($this->close($this->parseId($id), ProductStatus::Disposed));
    }

    public function restore(string $id): never
    {
        $productId = $this->parseId($id);
        $product = $this->find($productId);

        if (($product['status'] ?? '') === ProductStatus::Active->value) {
            throw new HttpException('Продукт не был списан', 409);
        }

        $this->products->restore($productId);

        Response::ok($this->format($this->find($productId)));
    }

    public function destroy(string $id): never
    {
        $productId = $this->parseId($id);
        $this->find($productId);
        $this->products->delete($productId);

        Response::ok(['id' => $productId]);
    }

    /** @return array<string, mixed> */
    private function close(int $id, ProductStatus $status): array
    {
        $this->find($id);

        if (!$this->products->close($id, $status)) {
            throw new HttpException('Продукт уже списан', 409);
        }

        return $this->format($this->find($id));
    }

    private function parseId(string $raw): int
    {
        if (!ctype_digit($raw) || (int) $raw < 1) {
            throw new HttpException('Некорректный идентификатор', 400);
        }

        return (int) $raw;
    }

    /** @return array<string, mixed> */
    private function find(int $id): array
    {
        $product = $this->products->find($id);

        if ($product === null) {
            throw new HttpException('Продукт не найден', 404);
        }

        return $product;
    }

    /** @return array<string, mixed> */
    private function format(array $row): array
    {
        $status = ProductStatus::tryFrom((string) ($row['status'] ?? '')) ?? ProductStatus::Active;
        $days = (int) ($row['days_remaining'] ?? Expiry::daysLeft((string) $row['expiry_date']));
        $threshold = (int) ($row['threshold_days'] ?? 7);
        $weight = Weight::fromGrams(max(1, (int) $row['weight_g']));

        return [
            'id'                    => (int) $row['id'],
            'name'                  => (string) $row['name'],
            'type'                  => (string) ($row['type'] ?? ''),
            'type_title'            => (string) ($row['type_title'] ?? ''),
            'weight_g'              => (int) $row['weight_g'],
            'weight_label'          => $weight->format(),
            'expiry_date'           => (string) $row['expiry_date'],
            'days_remaining'        => $days,
            'threshold_days'        => $threshold,
            'status'                => $status->value,
            'status_label'          => $status->label(),
            'is_expired'            => $days < 0,
            'is_soon'               => $days >= 0 && $days <= $threshold,
            'created_at'            => $row['created_at'] ?? null,
            'updated_at'            => $row['updated_at'] ?? null,
            'closed_at'             => $row['closed_at'] ?? null,
            'expiry_days_at_close'  => isset($row['expiry_days_at_close'])
                ? (int) $row['expiry_days_at_close']
                : null,
        ];
    }
}
