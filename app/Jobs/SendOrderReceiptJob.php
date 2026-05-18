<?php

namespace App\Jobs;

use App\Models\Order;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendOrderReceiptJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly int $orderId)
    {
        $this->onQueue('receipts');
    }

    public function handle(): void
    {
        $order = Order::query()->with('items')->findOrFail($this->orderId);

        Log::channel('nfr')->info('async_order_receipt_generated', [
            'order_id' => $order->id,
            'customer_email' => $order->customer_email,
            'total' => $order->total,
            'items_count' => $order->items->count(),
        ]);
    }
}
