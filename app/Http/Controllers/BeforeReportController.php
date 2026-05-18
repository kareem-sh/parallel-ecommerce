<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BeforeReportController extends Controller
{
    public function dailySales(Request $request): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());
        $started = microtime(true);
        $orders = Order::query()
            ->with('items:id,order_id,quantity')
            ->whereDate('created_at', $date)
            ->get();

        usleep(150000);

        return response()->json([
            'version' => 'before',
            'problem' => 'Loads all orders into memory and computes the report inside the user request.',
            'date' => $date,
            'orders_count' => $orders->count(),
            'items_sold' => $orders->sum(fn ($order) => $order->items->sum('quantity')),
            'gross_sales' => $orders->sum('total'),
            'duration_ms' => round((microtime(true) - $started) * 1000, 2),
        ])->header('X-Backend-Version', 'before');
    }
}
