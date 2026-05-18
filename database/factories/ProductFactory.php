<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sku'   => strtoupper(fake()->unique()->bothify('??-###-????')),
            'name'  => fake()->words(nb: 3, asText: true),
            'price' => fake()->randomFloat(2, 1.00, 9999.99),
            'stock' => fake()->numberBetween(0, 100_000),
        ];
    }

    /** Product with no stock — useful for testing stock-guard logic. */
    public function outOfStock(): static
    {
        return $this->state(['stock' => 0]);
    }

    /** Product priced in the max-validation range (up to 999 999.99). */
    public function expensive(): static
    {
        return $this->state([
            'price' => fake()->randomFloat(2, 10_000.00, 999_999.99),
        ]);
    }

    /** Well-stocked product, safe for order tests. */
    public function inStock(int $stock = 10_000): static
    {
        return $this->state(['stock' => $stock]);
    }
}
