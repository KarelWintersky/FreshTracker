<?php

namespace FreshTracker;

use DateTime;

class Validator
{
    public static function validateProductData(array $data, bool $isUpdate = false): array
    {
        $errors = [];
        $validation = App::$config['validation'];

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

    public static function processDateInput(string $input): string|false
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


}