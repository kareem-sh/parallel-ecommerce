<?php

namespace App\Jobs;

use App\Models\DailySalesSummary;
use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class BuildDailySalesSummaryJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly string $date)
    {
        $this->onQueue('reports');
    }

    public function handle(): void
    {
        $date = Carbon::parse($this->date)->toDateString();
        $ordersCount = 0;
        $itemsSold = 0;
        $grossSales = 0.0;
        $chunks = 0;

        Order::query()
            ->with('items:id,order_id,quantity')
            ->whereDate('created_at', $date)
            ->orderBy('id')
            ->chunkById(100, function ($orders) use (&$ordersCount, &$itemsSold, &$grossSales, &$chunks) {
                $chunks++;

                foreach ($orders as $order) {
                    $ordersCount++;
                    $grossSales += (float) $order->total;
                    $itemsSold += $order->items->sum('quantity');
                }
            });

        DailySalesSummary::updateOrCreate(
            ['sales_date' => $date],
            [
                'orders_count' => $ordersCount,
                'items_sold' => $itemsSold,
                'gross_sales' => $grossSales,
                'chunks_processed' => $chunks,
            ],
        );

        Log::channel('nfr')->info('daily_sales_summary_built_in_chunks', [
            'sales_date' => $date,
            'orders_count' => $ordersCount,
            'items_sold' => $itemsSold,
            'gross_sales' => $grossSales,
            'chunks_processed' => $chunks,
        ]);
    }
}
