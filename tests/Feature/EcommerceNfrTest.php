<?php

namespace Tests\Feature;

use App\Jobs\SendOrderReceiptJob;
use App\Models\DailySalesSummary;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class EcommerceNfrTest extends TestCase
{
    use RefreshDatabase;

    public function test_before_products_shows_uncached_problem(): void
    {
        Product::create([
            'sku' => 'OLD-1',
            'name' => 'Old Product',
            'price' => 10,
            'stock' => 5,
        ]);

        $response = $this->getJson('/api/before/products?limit=10');

        $response->assertOk()
            ->assertHeader('X-Backend-Version', 'before')
            ->assertJsonPath('version', 'before')
            ->assertJsonPath('cached', false)
            ->assertJsonCount(1, 'data');
    }

    public function test_after_products_uses_cache_on_second_request(): void
    {
        Product::create([
            'sku' => 'NEW-1',
            'name' => 'New Product',
            'price' => 15,
            'stock' => 7,
        ]);

        $this->getJson('/api/after/products?limit=10')
            ->assertOk()
            ->assertHeader('X-Backend-Version', 'after')
            ->assertHeader('X-Backend-Cache', 'miss')
            ->assertJsonPath('cached', false);

        $this->getJson('/api/after/products?limit=10')
            ->assertOk()
            ->assertHeader('X-Backend-Cache', 'hit')
            ->assertJsonPath('cached', true);
    }

    public function test_after_create_product_validates_and_invalidates_product_cache(): void
    {
        Product::create([
            'sku' => 'CACHE-1',
            'name' => 'Cached Product',
            'price' => 20,
            'stock' => 4,
        ]);

        $this->getJson('/api/after/products?limit=10')->assertHeader('X-Backend-Cache', 'miss');
        $this->getJson('/api/after/products?limit=10')->assertHeader('X-Backend-Cache', 'hit');

        $this->postJson('/api/after/products', [
            'sku' => 'CACHE-2',
            'name' => 'Fresh Product',
            'price' => 25,
            'stock' => 9,
        ])->assertCreated()
            ->assertHeader('X-Backend-Version', 'after')
            ->assertJsonPath('version', 'after');

        $this->getJson('/api/after/products?limit=10')
            ->assertHeader('X-Backend-Cache', 'miss')
            ->assertJsonCount(2, 'data');
    }

    public function test_after_create_order_decreases_stock_and_blocks_overselling(): void
    {
        Bus::fake([SendOrderReceiptJob::class]);

        $product = Product::create([
            'sku' => 'ORDER-1',
            'name' => 'Order Product',
            'price' => 30,
            'stock' => 3,
        ]);

        $this->postJson('/api/after/orders', [
            'customer_email' => 'buyer@example.com',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ])->assertCreated()
            ->assertHeader('X-Backend-Version', 'after')
            ->assertJsonPath('data.total', '60.00');

        Bus::assertDispatched(SendOrderReceiptJob::class);

        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'stock' => 1,
        ]);

        $this->postJson('/api/after/orders', [
            'customer_email' => 'buyer@example.com',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ])->assertStatus(409);
    }

    public function test_after_orders_uses_cache_on_second_request(): void
    {
        $product = Product::create([
            'sku' => 'ORDER-CACHE-1',
            'name' => 'Order Cache Product',
            'price' => 12,
            'stock' => 10,
        ]);

        $this->postJson('/api/after/orders', [
            'customer_email' => 'buyer@example.com',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 1],
            ],
        ])->assertCreated();

        $this->getJson('/api/after/orders?limit=10')
            ->assertOk()
            ->assertHeader('X-Backend-Cache', 'miss');

        $this->getJson('/api/after/orders?limit=10')
            ->assertOk()
            ->assertHeader('X-Backend-Cache', 'hit');
    }

    public function test_after_rejects_large_limits_and_bad_payloads(): void
    {
        $this->getJson('/api/after/products?limit=1000')
            ->assertStatus(422);

        $this->postJson('/api/after/orders', [
            'customer_email' => 'not-an-email',
            'items' => [],
        ])->assertStatus(422);
    }

    public function test_daily_sales_summary_is_built_in_chunks(): void
    {
        $product = Product::create([
            'sku' => 'SUMMARY-1',
            'name' => 'Summary Product',
            'price' => 40,
            'stock' => 10,
        ]);

        $this->postJson('/api/after/orders', [
            'customer_email' => 'buyer@example.com',
            'items' => [
                ['product_id' => $product->id, 'quantity' => 2],
            ],
        ])->assertCreated();

        $this->artisan('sales:summarize-daily', [
            'date' => now()->toDateString(),
            '--sync' => true,
        ])->assertSuccessful();

        $summary = DailySalesSummary::query()
            ->whereDate('sales_date', now()->toDateString())
            ->firstOrFail();

        $this->assertSame(1, $summary->orders_count);
        $this->assertSame(2, $summary->items_sold);
        $this->assertSame('80.00', $summary->gross_sales);
        $this->assertSame(1, $summary->chunks_processed);
    }
}
