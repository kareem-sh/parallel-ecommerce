<?php

namespace App\Services;

use App\Jobs\SendOrderReceiptJob;
use App\Models\Order;
use App\Models\Product;
use App\Support\NfrLogger;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

NfrLogger::error('before_products_loaded_without_cache', [
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

NfrLogger::success('after_products_loaded', [
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

    public function legacyHotProducts(int $limit): array
    {
        $started = microtime(true);

        $products = Product::query()
            ->orderByDesc('stock')
            ->limit($limit)
            ->get()
            ->map(fn (Product $product) => $this->withSlowLegacyScore($product))
            ->values()
            ->all();

        usleep(90000);

NfrLogger::error('before_hot_products_loaded_without_distributed_cache', [
            'limit' => $limit,
            'duration_ms' => $this->durationMs($started),
        ]);

        return [
            'version' => 'before',
            'requirement' => 6,
            'problem' => 'Hot products are read directly from the database every time.',
            'cached' => false,
            'duration_ms' => $this->durationMs($started),
            'data' => $products,
        ];
    }

    public function optimizedHotProducts(int $limit): array
    {
        $started = microtime(true);
        $key = "ecommerce:hot-products:v1:limit:{$limit}";
        $cached = Cache::has($key);

        $products = Cache::remember($key, now()->addSeconds(60), function () use ($limit) {
            return Product::query()
                ->select(['id', 'sku', 'name', 'price', 'stock', 'stock_version', 'updated_at'])
                ->orderByDesc('stock')
                ->limit($limit)
                ->get()
                ->values()
                ->all();
        });

NfrLogger::success('after_hot_products_loaded_from_distributed_cache', [
            'limit' => $limit,
            'cached' => $cached,
            'duration_ms' => $this->durationMs($started),
        ]);

        return [
            'version' => 'after',
            'requirement' => 6,
            'solution' => 'Hot products are stored in Redis so repeated reads avoid direct database queries.',
            'cached' => $cached,
            'duration_ms' => $this->durationMs($started),
            'data' => $products,
        ];
    }

    public function createLegacyProduct(array $data): array
    {
        $started = microtime(true);
        $product = Product::create($data);

NfrLogger::error('before_product_created_without_cache_invalidation', [
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

NfrLogger::success('after_product_created_and_cache_invalidated', [
            'product_id' => $product->id,
            'duration_ms' => $this->durationMs($started),
        ]);

        return [
            'version' => 'after',
            'solution' => 'Product is validated, stored transactionally, and product cache is invalidated.',
            'data' => $product,
        ];
    }

    public function legacyStockAdjustment(Product $product, int $delta): array
    {
        $started = microtime(true);
        $originalStock = $product->stock;

        usleep(80000);

        $product->stock = $originalStock + $delta;
        $product->save();

NfrLogger::error('before_stock_adjusted_without_concurrency_control', [
            'product_id' => $product->id,
            'delta' => $delta,
            'duration_ms' => $this->durationMs($started),
        ]);

        return [
            'version' => 'before',
            'requirement' => 7,
            'problem' => 'Stock adjustment uses read-modify-write without optimistic or pessimistic locking.',
            'locking' => 'none',
            'data' => $product->fresh(),
        ];
    }

    public function optimizedStockAdjustment(Product $product, int $delta): array
    {
        $started = microtime(true);
        $lock = Cache::lock("ecommerce:stock:adjust:{$product->id}", 10);

        try {
            $fresh = $lock->block(5, function () use ($product, $delta) {
                return DB::transaction(function () use ($product, $delta) {
                    $current = Product::query()
                        ->whereKey($product->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ($delta < 0 && $current->stock < abs($delta)) {
                        throw new RuntimeException('Not enough stock for this adjustment.');
                    }

                    $current->stock += $delta;
                    $current->stock_version += 1;
                    $current->save();

                    return $current->fresh();
                });
            });
        } catch (LockTimeoutException) {
            throw new RuntimeException('Inventory is busy. Could not acquire distributed lock.');
        }

        $this->forgetProductCaches();
        $this->forgetHotProductCaches();

        NfrLogger::success('after_stock_adjusted_with_distributed_lock', [
            'product_id' => $product->id,
            'delta' => $delta,
            'stock_version' => $fresh->stock_version,
            'duration_ms' => $this->durationMs($started),
        ]);

        return [
            'version' => 'after',
            'requirement' => 7,
            'solution' => 'Stock adjustment uses a Redis distributed lock per product.',
            'locking' => 'distributed',
            'data' => $fresh,
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

NfrLogger::error('before_orders_loaded_without_cache', [
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

NfrLogger::success('after_orders_loaded', [
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

NfrLogger::error('before_order_created_without_transaction_or_lock', [
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

NfrLogger::success('after_order_created_with_transaction_lock_and_cache_invalidation', [
            'order_id' => $order->id,
            'duration_ms' => $this->durationMs($started),
        ]);

        return [
            'version' => 'after',
            'solution' => 'Order uses Redis lock, database transaction, stock validation, and cache invalidation.',
            'data' => $order,
        ];
    }

    public function legacyCheckoutWithPayment(array $data): array
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

        $order->update(['total' => $total]);

        if ($data['fail_payment'] ?? false) {
    NfrLogger::error('before_checkout_left_partial_state_after_payment_failure', [
                'order_id' => $order->id,
                'duration_ms' => $this->durationMs($started),
            ]);

            throw new RuntimeException('Payment failed after stock and order were already changed.');
        }

        $order->update([
            'status' => 'paid',
            'payment_reference' => 'legacy-pay-' . $order->id,
            'paid_at' => now(),
        ]);

        return [
            'version' => 'before',
            'requirement' => 8,
            'problem' => 'Checkout is not atomic, so payment failure can leave partial order and stock changes.',
            'data' => $order->fresh()->load('items'),
        ];
    }

    public function optimizedCheckoutWithPayment(array $data): array
    {
        $started = microtime(true);

        $order = DB::transaction(function () use ($data) {
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

            if ($data['fail_payment'] ?? false) {
                throw new RuntimeException('Payment failed; transaction rolled back.');
            }

            $order->update([
                'status' => 'paid',
                'total' => $total,
                'payment_reference' => 'acid-pay-' . $order->id,
                'paid_at' => now(),
            ]);

            return $order->load('items');
        });

        $this->forgetProductCaches();
        $this->forgetHotProductCaches();
        $this->forgetOrderCaches();

NfrLogger::success('after_checkout_committed_atomically', [
            'order_id' => $order->id,
            'duration_ms' => $this->durationMs($started),
        ]);

        return [
            'version' => 'after',
            'requirement' => 8,
            'solution' => 'Payment, inventory update, and order creation are protected by one ACID transaction.',
            'data' => $order,
        ];
    }

    public function legacyBenchmarkProducts(int $limit): array
    {
        $payload = $this->legacyHotProducts($limit);

        return [
            'version' => 'before',
            'requirement' => 10,
            'operation' => 'hot-products',
            'bottleneck' => 'Repeated direct database scan plus per-product recalculation.',
            'duration_ms' => $payload['duration_ms'],
            'cached' => false,
        ];
    }

    public function optimizedBenchmarkProducts(int $limit): array
    {
        $payload = $this->optimizedHotProducts($limit);

        return [
            'version' => 'after',
            'requirement' => 10,
            'operation' => 'hot-products',
            'bottleneck_removed' => 'Redis serves repeated hot-product reads instead of recalculating every request.',
            'duration_ms' => $payload['duration_ms'],
            'cached' => $payload['cached'],
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

    private function forgetHotProductCaches(): void
    {
        foreach ([10, 20, 50, 100] as $limit) {
            Cache::forget("ecommerce:hot-products:v1:limit:{$limit}");
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
    
    public function processDailySalesReport(): array
    {
        $started = microtime(true);

        $processed = 0;
        $totalRevenue = 0;

        Order::query()
            ->with('items')
            ->chunk(100, function ($orders) use (&$processed, &$totalRevenue) {

                foreach ($orders as $order) {
                    $processed++;

                    $totalRevenue += $order->total;
                }
            });

NfrLogger::success('daily_sales_processed_in_chunks', [
            'processed_orders' => $processed,
            'total_revenue' => $totalRevenue,
            'duration_ms' => $this->durationMs($started),
        ]);

        return [
            'processed_orders' => $processed,
            'total_revenue' => $totalRevenue,
            'duration_ms' => $this->durationMs($started),
            'solution' => 'Large datasets are processed in chunks to reduce memory usage.',
        ];
    }
}
