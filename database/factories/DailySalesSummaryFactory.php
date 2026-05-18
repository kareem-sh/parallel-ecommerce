<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class DailySalesSummaryFactory extends Factory
{
    public function definition(): array
    {
        $ordersCount = fake()->numberBetween(10, 500);
        $itemsSold   = $ordersCount * fake()->numberBetween(1, 5);
        $grossSales  = round(fake()->randomFloat(2, $ordersCount * 50, $ordersCount * 800), 2);
        $chunks      = (int) ceil($ordersCount / 100);

        return [
            // Safe default range: older than 7 days, avoids the real-data window
            'sales_date'       => fake()->dateTimeBetween('-90 days', '-8 days')->format('Y-m-d'),
            'orders_count'     => $ordersCount,
            'items_sold'       => $itemsSold,
            'gross_sales'      => $grossSales,
            'chunks_processed' => $chunks,
        ];
    }
}
