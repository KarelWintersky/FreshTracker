<?php

declare(strict_types=1);

namespace FreshTracker\Controllers;

use FreshTracker\Bootstrap\App;
use FreshTracker\Http\Response;
use FreshTracker\Repositories\ProductRepository;

final class StatsController
{
    public function index(): never
    {
        $products = new ProductRepository(App::db());
        $active = $products->active();
        $closed = $products->history();

        $counts = ['active' => 0, 'expired' => 0, 'soon' => 0, 'fresh' => 0];
        $activeWeight = 0;

        foreach ($active as $row) {
            $counts['active']++;
            $activeWeight += (int) $row['weight_g'];

            $days = (int) $row['days_remaining'];
            $threshold = (int) $row['threshold_days'];

            if ($days < 0) {
                $counts['expired']++;
            } elseif ($days <= $threshold) {
                $counts['soon']++;
            } else {
                $counts['fresh']++;
            }
        }

        $used = 0;
        $disposed = 0;
        $usedGrams = 0;
        $disposedGrams = 0;
        $months = [];

        foreach ($closed as $row) {
            $grams = (int) $row['weight_g'];
            $status = (string) $row['status'];
            $stamp = (string) ($row['closed_at'] ?? $row['updated_at'] ?? '');
            $month = strlen($stamp) >= 7 ? substr($stamp, 0, 7) : 'unknown';

            $months[$month] ??= [
                'month'        => $month,
                'used'         => 0,
                'disposed'     => 0,
                'used_g'       => 0,
                'disposed_g'   => 0,
            ];

            if ($status === 'used') {
                $used++;
                $usedGrams += $grams;
                $months[$month]['used']++;
                $months[$month]['used_g'] += $grams;
            } elseif ($status === 'disposed') {
                $disposed++;
                $disposedGrams += $grams;
                $months[$month]['disposed']++;
                $months[$month]['disposed_g'] += $grams;
            }
        }

        krsort($months);

        $total = $used + $disposed;

        Response::ok([
            'counts' => $counts,
            'weight' => [
                'active_g' => $activeWeight,
            ],
            'history' => [
                'used'          => $used,
                'disposed'      => $disposed,
                'used_g'        => $usedGrams,
                'disposed_g'    => $disposedGrams,
                'waste_percent' => $total > 0 ? (int) round($disposed / $total * 100) : 0,
            ],
            'months' => array_values(array_slice($months, 0, 6, true)),
        ]);
    }
}
