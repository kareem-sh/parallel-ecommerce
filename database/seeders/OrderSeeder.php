<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class OrderSeeder extends Seeder
{
    private const TOTAL_ORDERS    = 600;
    private const MAX_DAYS_BACK   = 30;
    private const MAX_ITEMS_PER_ORDER = 4;

    public function run(): void
    {
        $products = Product::all();

        if ($products->isEmpty()) {
            $this->command->warn('OrderSeeder: no products found — run ProductSeeder first.');
            return;
        }

        if (Order::query()->count() >= self::TOTAL_ORDERS) {
            $this->command->info('OrderSeeder: already seeded, skipping.');
            return;
        }

        $this->command->getOutput()->progressStart(self::TOTAL_ORDERS);

        for ($i = 0; $i < self::TOTAL_ORDERS; $i++) {

            DB::transaction(function () use ($products, &$createdAt) {
                $createdAt = Carbon::now()
                    ->subDays(rand(0, self::MAX_DAYS_BACK - 1))
                    ->subSeconds(rand(0, 86_399));

                $order = Order::create([
                    'customer_email' => fake()->safeEmail(),
                    'status'         => fake()->randomElement(['created', 'processing', 'shipped', 'delivered']),
                    'total'          => 0,
                    'created_at'     => $createdAt,
                    'updated_at'     => $createdAt,
                ]);

                $total          = 0.0;
                $itemCount      = rand(1, self::MAX_ITEMS_PER_ORDER);
                $pickedProducts = $products->random(min($itemCount, $products->count()));

                foreach ($pickedProducts as $product) {
                    $quantity  = rand(1, 5);
                    $unitPrice = (float) $product->price;
                    $lineTotal = round($unitPrice * $quantity, 2);
                    $total    += $lineTotal;

                    $order->items()->create([
                        'product_id' => $product->id,
                        'quantity'   => $quantity,
                        'unit_price' => $unitPrice,
                        'line_total' => $lineTotal,
                    ]);
                }

                $order->update(['total' => round($total, 2)]);
            });

            $this->command->getOutput()->progressAdvance();
        }

        $this->command->getOutput()->progressFinish();
        $this->command->info('OrderSeeder: '.Order::count().' orders ready.');
    }
}
