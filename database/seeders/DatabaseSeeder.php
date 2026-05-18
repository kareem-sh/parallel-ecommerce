<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::updateOrCreate(
            ['email' => 'test@example.com'],
            ['name' => 'Test User', 'password' => bcrypt('password')],
        );

        foreach ([
            [
                'sku' => 'PHONE-001',
                'name' => 'Smart Phone',
                'price' => 499.00,
                'stock' => 100000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'sku' => 'LAPTOP-001',
                'name' => 'Business Laptop',
                'price' => 1199.00,
                'stock' => 500000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'sku' => 'HEADSET-001',
                'name' => 'Wireless Headset',
                'price' => 89.00,
                'stock' => 200000,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ] as $product) {
            Product::updateOrCreate(
                ['sku' => $product['sku']],
                [
                    'name' => $product['name'],
                    'price' => $product['price'],
                    'stock' => $product['stock'],
                ],
            );
        }

        $products = Product::query()->orderBy('id')->take(3)->get();

        if (Order::query()->count() < 200 && $products->count() >= 3) {
            for ($i = 1; $i <= 200; $i++) {
                $product = $products[$i % $products->count()];
                $quantity = ($i % 3) + 1;
                $lineTotal = (float) $product->price * $quantity;

                $order = Order::create([
                    'customer_email' => "seed-buyer-{$i}@example.com",
                    'status' => 'created',
                    'total' => $lineTotal,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                    'line_total' => $lineTotal,
                ]);
            }
        }
    }
}
