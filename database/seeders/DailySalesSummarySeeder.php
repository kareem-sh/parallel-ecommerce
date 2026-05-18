<?php

namespace Database\Seeders;

use App\Models\Order;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DailySalesSummarySeeder extends Seeder
{
    private const REAL_WINDOW_DAYS = 7;
    private const HISTORICAL_DAYS  = 23;

    public function run(): void
    {
        DB::transaction(function () {
            $this->seedRealWindow();
            $this->seedHistoricalWindow();
        });

        $total = DB::table('daily_sales_summaries')->count();
        $this->command->info("DailySalesSummarySeeder: {$total} summaries ready.");
    }

    // ── Real summaries derived from actual seeded orders ────────────────────

    private function seedRealWindow(): void
    {
        for ($d = self::REAL_WINDOW_DAYS - 1; $d >= 0; $d--) {
            $date = Carbon::today()->subDays($d)->toDateString(); // plain "Y-m-d"

            $orders = Order::query()
                ->with('items:id,order_id,quantity')
                ->whereDate('created_at', $date)
                ->get();

            if ($orders->isEmpty()) {
                $this->command->line("  - {$date}: no orders, skipped.");
                continue;
            }

            $ordersCount = $orders->count();
            $itemsSold   = $orders->sum(fn ($o) => $o->items->sum('quantity'));
            $grossSales  = round((float) $orders->sum('total'), 2);
            $chunks      = (int) ceil($ordersCount / 100);

            $now     = now()->toDateTimeString();
            $payload = [
                'orders_count'     => $ordersCount,
                'items_sold'       => $itemsSold,
                'gross_sales'      => $grossSales,
                'chunks_processed' => $chunks,
                'updated_at'       => $now,
            ];

            // DB::table() — bypasses date cast, plain string WHERE comparison
            $exists = DB::table('daily_sales_summaries')
                ->where('sales_date', $date)
                ->exists();

            if ($exists) {
                DB::table('daily_sales_summaries')
                    ->where('sales_date', $date)
                    ->update($payload);
            } else {
                DB::table('daily_sales_summaries')
                    ->insert(array_merge($payload, [
                        'sales_date' => $date,
                        'created_at' => $now,
                    ]));
            }

            $this->command->line(
                "  ✓ {$date}: {$ordersCount} orders / {$itemsSold} items / {$grossSales} gross"
            );
        }
    }

    // ── Fake historical summaries — only missing dates ───────────────────────

    private function seedHistoricalWindow(): void
    {
        $missingDates = $this->missingHistoricalDates();

        if ($missingDates->isEmpty()) {
            $this->command->line('  - Historical summaries already seeded, skipping.');
            return;
        }

        $now  = now()->toDateTimeString();
        $rows = $missingDates->map(function (string $date) use ($now) {
            $ordersCount = fake()->numberBetween(10, 500);

            return [
                'sales_date'       => $date,                                          // plain string
                'orders_count'     => $ordersCount,
                'items_sold'       => $ordersCount * fake()->numberBetween(1, 5),
                'gross_sales'      => round(
                    fake()->randomFloat(2, $ordersCount * 50, $ordersCount * 800), 2
                ),
                'chunks_processed' => (int) ceil($ordersCount / 100),
                'created_at'       => $now,
                'updated_at'       => $now,
            ];
        })->all();

        // insertOrIgnore as a final safety net against any remaining edge case
        DB::table('daily_sales_summaries')->insertOrIgnore($rows);

        $this->command->line("  ✓ Created {$missingDates->count()} historical summaries.");
    }

    /**
     * Dates in the historical window (day -8 → day -30) that are not yet in the DB.
     * Uses DB::table() + whereIn() with plain strings — no Eloquent date casting.
     */
    private function missingHistoricalDates(): Collection
    {
        $intended = collect(range(0, self::HISTORICAL_DAYS - 1))
            ->map(fn (int $i) => Carbon::today()->subDays(8 + $i)->toDateString());

        $existing = DB::table('daily_sales_summaries')
            ->whereIn('sales_date', $intended->all())   // plain string IN (...)
            ->pluck('sales_date')
            ->map(fn ($d) => substr($d, 0, 10));        // trim any " 00:00:00" SQLite appends

        return $intended->diff($existing)->values();
    }
}
