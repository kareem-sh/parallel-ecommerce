<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    private const CATALOG = [
        ['sku' => 'PHONE-001',   'name' => 'Smart Phone',      'price' => 499.00,  'stock' => 100_000],
        ['sku' => 'LAPTOP-001',  'name' => 'Business Laptop',  'price' => 1199.00, 'stock' => 50_000],
        ['sku' => 'HEADSET-001', 'name' => 'Wireless Headset', 'price' => 89.00,   'stock' => 200_000],
        ['sku' => 'TABLET-001',  'name' => 'Android Tablet',   'price' => 329.00,  'stock' => 75_000],
        ['sku' => 'WATCH-001',   'name' => 'Smart Watch',      'price' => 249.00,  'stock' => 60_000],
    ];

    private const RANDOM_PRODUCTS = 15;

    public function run(): void
    {
        // Fixed catalog — always safe to upsert
        foreach (self::CATALOG as $row) {
            Product::updateOrCreate(['sku' => $row['sku']], $row);
        }

        // Random products — only fill up to the target, never duplicate on re-run
        $target  = count(self::CATALOG) + self::RANDOM_PRODUCTS;
        $missing = $target - Product::count();

        if ($missing > 0) {
            Product::factory($missing)->create();
        }

        $this->command->info("ProductSeeder: " . Product::count() . " products ready.");
    }
}
