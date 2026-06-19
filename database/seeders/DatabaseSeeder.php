<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\DailySalesReportSeeder;
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

        $this->call(DailySalesReportSeeder::class);
    }
}
