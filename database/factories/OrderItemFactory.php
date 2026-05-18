<?php

namespace Database\Factories;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrderItemFactory extends Factory
{
    public function definition(): array
    {
        // Reuse an existing product when available to avoid bloating the table
        $product   = Product::query()->inRandomOrder()->first()
                     ?? Product::factory()->inStock()->create();

        $quantity  = fake()->numberBetween(1, 10);
        $unitPrice = (float) $product->price;
        $lineTotal = round($unitPrice * $quantity, 2);

        return [
            'order_id'   => Order::factory(),
            'product_id' => $product->id,
            'quantity'   => $quantity,
            'unit_price' => $unitPrice,
            'line_total' => $lineTotal,
        ];
    }

    /** Force a specific product (price + stock come from it). */
    public function forProduct(Product $product): static
    {
        return $this->state(function (array $attributes) use ($product) {
            $quantity  = $attributes['quantity'];
            $unitPrice = (float) $product->price;

            return [
                'product_id' => $product->id,
                'unit_price' => $unitPrice,
                'line_total' => round($unitPrice * $quantity, 2),
            ];
        });
    }
}
