<?php

declare(strict_types=1);

namespace FreshTracker\Domain;

use FreshTracker\Http\HttpException;
use FreshTracker\Repositories\TypeRepository;

final class ProductValidator
{
    public function __construct(
        private readonly TypeRepository $types,
        private readonly array $rules,
    ) {
    }

    /**
     * @return array<string, mixed> normalized fields
     * @throws HttpException with 422 when validation fails
     */
    public function validate(array $data, bool $partial = false): array
    {
        $errors = [];
        $result = [];

        $has = static fn (string $key): bool => array_key_exists($key, $data);

        if (!$partial || $has('name')) {
            $name = trim((string) ($data['name'] ?? ''));
            $max = (int) ($this->rules['max_name_length'] ?? 255);

            if ($name === '') {
                $errors[] = 'Название обязательно';
            } elseif (mb_strlen($name) > $max) {
                $errors[] = "Название не должно превышать {$max} символов";
            } else {
                $result['name'] = $name;
            }
        }

        if (!$partial || $has('type') || $has('type_id')) {
            $type = $this->types->findBySlugOrId($data['type'] ?? $data['type_id'] ?? null);
            if ($type === null) {
                $errors[] = 'Неизвестный тип продукта';
            } else {
                $result['type_id'] = (int) $type['id'];
            }
        }

        if (!$partial || $has('weight') || $has('weight_g')) {
            try {
                $raw = $has('weight_g') ? $data['weight_g'] : ($data['weight'] ?? null);
                $unit = $has('weight_g') ? 'g' : (string) ($data['weight_unit'] ?? 'g');
                $weight = Weight::fromInput($raw, $unit);

                $min = (int) ($this->rules['min_weight_g'] ?? 1);
                $max = (int) ($this->rules['max_weight_g'] ?? 5_000_000);

                if ($weight->grams < $min || $weight->grams > $max) {
                    $errors[] = 'Вес вне допустимого диапазона';
                } else {
                    $result['weight_g'] = $weight->grams;
                }
            } catch (\InvalidArgumentException) {
                $errors[] = 'Вес должен быть положительным числом';
            }
        }

        if (!$partial || $has('expiry_date')) {
            $date = Expiry::normalize($data['expiry_date'] ?? null);
            if ($date === null) {
                $errors[] = 'Некорректная дата';
            } else {
                $result['expiry_date'] = $date;
            }
        }

        if (!$partial || $has('threshold_days')) {
            $threshold = filter_var($data['threshold_days'] ?? null, FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 1,
                    'max_range' => (int) ($this->rules['max_threshold_days'] ?? 365),
                ],
            ]);

            if ($threshold === false) {
                $errors[] = 'Порог предупреждения вне диапазона';
            } else {
                $result['threshold_days'] = (int) $threshold;
            }
        }

        if ($errors !== []) {
            throw new HttpException(implode('; ', $errors), 422);
        }

        return $result;
    }
}
