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
        $payload = $this->service->optimizedProducts($this->limit($request, 100));

        usleep(50000000); // Simulate processing delay so capacity limiter can be tested

        return response()->json($payload)
            ->header('X-Backend-Version', 'after')
            ->header('X-Backend-Cache', $payload['cached'] ? 'hit' : 'miss')
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

        return response()->json($this->service->createOptimizedProduct($data), 201)
            ->header('X-Backend-Version', 'after');
    }

    public function orders(Request $request): JsonResponse
    {
        $payload = $this->service->optimizedOrders($this->limit($request, 100));

        return response()->json($payload)
            ->header('X-Backend-Version', 'after')
            ->header('X-Backend-Cache', $payload['cached'] ? 'hit' : 'miss');
    }

    public function createOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_email' => ['required', 'email', 'max:160'],
            'items' => ['required', 'array', 'min:1', 'max:20'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100'],
        ]);

        try {
            return response()->json($this->service->createOptimizedOrder($data), 201)
                ->header('X-Backend-Version', 'after');
        } catch (LockTimeoutException | RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409)->header('X-Backend-Version', 'after');
        }
    }

    private function limit(Request $request, int $max): int
    {
        $validated = validator($request->query(), [
            'limit' => ['sometimes', 'integer', 'min:1', "max:{$max}"],
        ])->validate();

        return (int) ($validated['limit'] ?? 20);
    }
}
