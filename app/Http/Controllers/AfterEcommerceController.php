<?php

namespace App\Http\Controllers;

use App\Services\EcommerceNfrService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class AfterEcommerceController extends Controller
{
    public function __construct(private readonly EcommerceNfrService $service)
    {
    }

    public function products(Request $request): JsonResponse
    {
        $requestId = $this->requestId();

        $payload = $this->service->optimizedProducts(
            $this->limit($request, 100)
        );

        $this->simulateWork($request);

        return response()->json($payload)
            ->header('X-Backend-Version', 'after')
            ->header('X-Backend-Cache', $payload['cached'] ? 'hit' : 'miss')
            ->header('X-Request-Id', $requestId)
            ->header('Cache-Control', 'public, max-age=30');
    }

    public function hotProducts(Request $request): JsonResponse
    {
        $requestId = $this->requestId();
        $payload = $this->service->optimizedHotProducts($this->limit($request, 100));

        return response()->json($payload)
            ->header('X-Backend-Version', 'after')
            ->header('X-Backend-Cache', $payload['cached'] ? 'hit' : 'miss')
            ->header('X-Request-Id', $requestId)
            ->header('Cache-Control', 'public, max-age=30');
    }

    public function createProduct(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:80', 'unique:products,sku'],
            'name' => ['required', 'string', 'max:160'],
            'price' => ['required', 'numeric', 'min:0.01', 'max:999999.99'],
            'stock' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);
        $requestId = $this->requestId();
        return response()->json($this->service->createOptimizedProduct($data), 201)
            ->header('X-Backend-Version', 'after')
            ->header('X-Request-Id', $requestId);
    }

    public function orders(Request $request): JsonResponse
    {
        $payload = $this->service->optimizedOrders($this->limit($request, 100));
        $requestId = $this->requestId();
        return response()->json($payload)
            ->header('X-Backend-Version', 'after')
            ->header('X-Backend-Cache', $payload['cached'] ? 'hit' : 'miss')
            ->header('X-Request-Id', $requestId);
    }

    public function createOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_email' => ['required', 'email', 'max:160'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);
        $requestId = $this->requestId();
        try {
            return response()->json($this->service->createOptimizedOrder($data), 201)
                ->header('X-Backend-Version', 'after')
                ->header('X-Request-Id', $requestId);
        } catch (LockTimeoutException | RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409)->header('X-Backend-Version', 'after')
            ->header('X-Request-Id', $requestId);
        }
    }

    public function adjustStock(Request $request, int $product): JsonResponse
    {
        $data = $request->validate([
            'delta' => ['required', 'integer', 'between:-1000,1000'],
            'expected_version' => ['sometimes', 'integer', 'min:0'],
        ]);

        $requestId = $this->requestId();
        $productModel = \App\Models\Product::findOrFail($product);

        try {
            return response()->json($this->service->optimizedStockAdjustment(
                $productModel,
                (int) $data['delta'],
                array_key_exists('expected_version', $data) ? (int) $data['expected_version'] : null,
            ))->header('X-Backend-Version', 'after')
                ->header('X-Request-Id', $requestId);
        } catch (RuntimeException $exception) {
            return response()->json([
                'version' => 'after',
                'requirement' => 7,
                'message' => $exception->getMessage(),
            ], 409)->header('X-Backend-Version', 'after')
                ->header('X-Request-Id', $requestId);
        }
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_email' => ['required', 'email', 'max:160'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
            'fail_payment' => ['sometimes', 'boolean'],
        ]);

        $requestId = $this->requestId();

        try {
            return response()->json($this->service->optimizedCheckoutWithPayment($data), 201)
                ->header('X-Backend-Version', 'after')
                ->header('X-Request-Id', $requestId);
        } catch (RuntimeException $exception) {
            return response()->json([
                'version' => 'after',
                'requirement' => 8,
                'message' => $exception->getMessage(),
                'rolled_back' => true,
            ], 402)->header('X-Backend-Version', 'after')
                ->header('X-Request-Id', $requestId);
        }
    }

    public function benchmarkProducts(Request $request): JsonResponse
    {
        $payload = $this->service->optimizedBenchmarkProducts($this->limit($request, 100));

        return response()->json($payload)
            ->header('X-Backend-Version', 'after')
            ->header('X-Backend-Cache', $payload['cached'] ? 'hit' : 'miss')
            ->header('X-Benchmark-Bottleneck', 'redis-cache');
    }

    private function limit(Request $request, int $max): int
    {
        $validated = validator($request->query(), [
            'limit' => ['sometimes', 'integer', 'min:1', "max:{$max}"],
        ])->validate();

        return (int) ($validated['limit'] ?? 20);
    }

    private function requestId(): string
    {
        return (string) str()->uuid();
    }

    private function simulateWork(Request $request): void
    {
        $milliseconds = (int) $request->query('simulate_ms', 0);

        if ($milliseconds <= 0) {
            return;
        }

        usleep(min($milliseconds, 1000) * 1000);
    }
}
