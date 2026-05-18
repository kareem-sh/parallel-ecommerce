<?php

namespace App\Services;

use App\Jobs\SendOrderReceiptJob;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class EcommerceNfrService
{
    public function legacyProducts(int $limit): array
    {
        $started = microtime(true);
        $products = Product::query()
            ->oldest()
            ->limit($limit)
            ->get()
            ->map(function (Product $product) {
                return $this->withSlowLegacyScore($product);
            })
            ->values()
            ->all();

        usleep(80000);

        Log::channel('nfr')->warning('before_products_loaded_without_cache', [
            'limit' => $limit,
            'duration_ms' => $this->durationMs($started),
        ]);

        return [
            'version' => 'before',
            'problem' => 'Products are recalculated on every request without Redis cache.',
            'cached' => false,
            'duration_ms' => $this->durationMs($started),
            'data' => $products,
        ];
    }

    public function optimizedProducts(int $limit): array
    {
        $started = microtime(true);
        $key = "ecommerce:products:v1:limit:{$limit}";
        $cached = Cache::has($key);

        $data = Cache::remember($key, now()->addSeconds(60), function () use ($limit) {
            return Product::query()
                ->select(['id', 'sku', 'name', 'price', 'stock', 'updated_at'])
                ->latest()
                ->limit($limit)
                ->get()
                ->values()
                ->all();
        });

        Log::channel('nfr')->info('after_products_loaded', [
            'limit' => $limit,
            'cached' => $cached,
            'duration_ms' => $this->durationMs($started),
        ]);

        return [
            'version' => 'after',
            'solution' => 'Products are served from Redis cache and invalidated after create/order changes.',
            'cached' => $cached,
            'duration_ms' => $this->durationMs($started),
            'data' => $data,
        ];
    }

    public function createLegacyProduct(array $data): array
    {
        $started = microtime(true);
        $product = Product::create($data);

        Log::channel('nfr')->warning('before_product_created_without_cache_invalidation', [
            'product_id' => $product->id,
            'duration_ms' => $this->durationMs($started),
        ]);

        return [
            'version' => 'before',
            'problem' => 'Create does not invalidate any product cache and has only basic protection.',
            'data' => $product,
        ];
    }

    public function createOptimizedProduct(array $data): array
    {
        $started = microtime(true);
        $product = DB::transaction(fn() => Product::create($data));

        $this->forgetProductCaches();

        Log::channel('nfr')->info('after_product_created_and_cache_invalidated', [
            'product_id' => $product->id,
            'duration_ms' => $this->durationMs($started),
        ]);

        return [
            'version' => 'after',
            'solution' => 'Product is validated, stored transactionally, and product cache is invalidated.',
            'data' => $product,
        ];
    }

    public function legacyOrders(int $limit): array
    {
        $started = microtime(true);
        $orders = Order::query()
            ->with('items.product:id,sku,name')
            ->latest()
            ->limit($limit)
            ->get();

        usleep(70000);

        Log::channel('nfr')->warning('before_orders_loaded_without_cache', [
            'limit' => $limit,
            'duration_ms' => $this->durationMs($started),
        ]);

        return [
            'version' => 'before',
            'problem' => 'Orders are fetched repeatedly without cache or tight limits.',
            'cached' => false,
            'duration_ms' => $this->durationMs($started),
            'data' => $orders,
        ];
    }

    public function optimizedOrders(int $limit): array
    {
        $started = microtime(true);
        $key = "ecommerce:orders:v1:limit:{$limit}";
        $cached = Cache::has($key);

        $orders = Cache::remember($key, now()->addSeconds(30), function () use ($limit) {
            return Order::query()
                ->with('items.product:id,sku,name')
                ->latest()
                ->limit($limit)
                ->get();
        });

        Log::channel('nfr')->info('after_orders_loaded', [
            'limit' => $limit,
            'cached' => $cached,
            'duration_ms' => $this->durationMs($started),
        ]);

        return [
            'version' => 'after',
            'solution' => 'Recent orders are cached briefly in Redis for dashboard/API reads.',
            'cached' => $cached,
            'duration_ms' => $this->durationMs($started),
            'data' => $orders,
        ];
    }

    public function createLegacyOrder(array $data): array
    {
        $started = microtime(true);
        $order = Order::create([
            'customer_email' => $data['customer_email'],
            'status' => 'created',
            'total' => 0,
        ]);

        $total = 0;

        foreach ($data['items'] as $item) {
            $product = Product::findOrFail($item['product_id']);
            $lineTotal = (float) $product->price * (int) $item['quantity'];
            $total += $lineTotal;

            $order->items()->create([
                'product_id' => $product->id,
                'quantity' => $item['quantity'],
                'unit_price' => $product->price,
                'line_total' => $lineTotal,
            ]);

            $product->decrement('stock', $item['quantity']);
        }

        usleep(120000);

        $order->update(['total' => $total]);

        Log::channel('nfr')->warning('before_order_created_without_transaction_or_lock', [
            'order_id' => $order->id,
            'duration_ms' => $this->durationMs($started),
        ]);

        return [
            'version' => 'before',
            'problem' => 'Order creation updates stock without a transaction or concurrency lock.',
            'data' => $order->load('items.product:id,sku,name'),
        ];
    }

    public function createOptimizedOrder(array $data): array
    {
        $started = microtime(true);
        $productIds = collect($data['items'])->pluck('product_id')->sort()->values()->all();
        $lock = Cache::lock('ecommerce:order:create:' . implode('-', $productIds), 10);

        try {
            $order = $lock->block(5, function () use ($data) {
                return DB::transaction(function () use ($data) {
                    $order = Order::create([
                        'customer_email' => $data['customer_email'],
                        'status' => 'created',
                        'total' => 0,
                    ]);

                    $total = 0;

                    foreach ($data['items'] as $item) {
                        $product = Product::query()
                            ->whereKey($item['product_id'])
                            ->lockForUpdate()
                            ->firstOrFail();

                        if ($product->stock < $item['quantity']) {
                            throw new RuntimeException("Product {$product->id} does not have enough stock.");
                        }

                        $lineTotal = (float) $product->price * (int) $item['quantity'];
                        $total += $lineTotal;

                        $order->items()->create([
                            'product_id' => $product->id,
                            'quantity' => $item['quantity'],
                            'unit_price' => $product->price,
                            'line_total' => $lineTotal,
                        ]);

                        $product->decrement('stock', $item['quantity']);
                    }

                    $order->update(['total' => $total]);

                    return $order->load('items.product:id,sku,name');
                });
            });
        } finally {
            optional($lock)->release();
        }

        $this->forgetProductCaches();
        $this->forgetOrderCaches();

        SendOrderReceiptJob::dispatch($order->id);

        Log::channel('nfr')->info('after_order_created_with_transaction_lock_and_cache_invalidation', [
            'order_id' => $order->id,
            'duration_ms' => $this->durationMs($started),
        ]);

        return [
            'version' => 'after',
            'solution' => 'Order uses Redis lock, database transaction, stock validation, and cache invalidation.',
            'data' => $order,
        ];
    }

    private function withSlowLegacyScore(Product $product): array
    {
        $score = 0;

        for ($i = 1; $i <= 300; $i++) {
            $score += (($product->id * $i) % 97) + (($product->stock + $i) % 23);
        }

        return [
            'id' => $product->id,
            'sku' => $product->sku,
            'name' => $product->name,
            'price' => $product->price,
            'stock' => $product->stock,
            'legacy_score' => $score,
        ];
    }

    private function forgetProductCaches(): void
    {
        foreach ([10, 20, 50, 100] as $limit) {
            Cache::forget("ecommerce:products:v1:limit:{$limit}");
        }
    }

    private function forgetOrderCaches(): void
    {
        foreach ([10, 20, 50, 100] as $limit) {
            Cache::forget("ecommerce:orders:v1:limit:{$limit}");
        }
    }

    private function durationMs(float $started): float
    {
        return round((microtime(true) - $started) * 1000, 2);
    }
}
