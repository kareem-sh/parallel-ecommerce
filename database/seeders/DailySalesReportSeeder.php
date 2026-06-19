<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class DailySalesReportSeeder extends Seeder
{
    public function run(): void
    {
        $products = Product::query()->orderBy('id')->take(3)->get();

        if ($products->isEmpty()) {
            return;
        }

        $date = Carbon::now('UTC')->toDateString();
        $dayStart = Carbon::parse($date, 'UTC')->startOfDay();

        Order::query()
            ->whereDate('created_at', $date)
            ->each(function (Order $order) {
                $order->items()->delete();
                $order->delete();
            });

        for ($i = 1; $i <= 1500; $i++) {
            $product = $products[($i - 1) % $products->count()];
            $quantity = ($i % 3) + 1;
            $lineTotal = (float) $product->price * $quantity;

            $order = Order::create([
                'customer_email' => "report-buyer-{$i}@example.com",
                'status' => 'created',
                'total' => $lineTotal,
                'created_at' => $dayStart->copy()->addSeconds($i % 86400),
                'updated_at' => $dayStart->copy()->addSeconds($i % 86400),
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
