<?php

namespace App\Http\Controllers;

use App\Services\EcommerceNfrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class BeforeEcommerceController extends Controller
{
    public function __construct(private readonly EcommerceNfrService $service)
    {
    }

    public function products(Request $request): JsonResponse
    {
        return response()->json($this->service->legacyProducts($this->limit($request, 100)))
            ->header('X-Backend-Version', 'before')
            ->header('Cache-Control', 'no-store');
    }

    public function hotProducts(Request $request): JsonResponse
    {
        return response()->json($this->service->legacyHotProducts($this->limit($request, 100)))
            ->header('X-Backend-Version', 'before')
            ->header('X-Backend-Cache', 'none')
            ->header('Cache-Control', 'no-store');
    }

    public function createProduct(Request $request): JsonResponse
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:80', 'unique:products,sku'],
            'name' => ['required', 'string', 'max:160'],
            'price' => ['required', 'numeric', 'min:0.01'],
            'stock' => ['required', 'integer', 'min:0'],
        ]);

        return response()->json($this->service->createLegacyProduct($data), 201)
            ->header('X-Backend-Version', 'before');
    }

    public function orders(Request $request): JsonResponse
    {
        return response()->json($this->service->legacyOrders($this->limit($request, 100)))
            ->header('X-Backend-Version', 'before')
            ->header('Cache-Control', 'no-store');
    }

    public function createOrder(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_email' => ['required', 'email', 'max:160'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        return response()->json($this->service->createLegacyOrder($data), 201)
            ->header('X-Backend-Version', 'before');
    }

    public function adjustStock(Request $request, int $product): JsonResponse
    {
        $data = $request->validate([
            'delta' => ['required', 'integer', 'between:-1000,1000'],
        ]);

        $productModel = \App\Models\Product::findOrFail($product);

        return response()->json($this->service->legacyStockAdjustment($productModel, (int) $data['delta']))
            ->header('X-Backend-Version', 'before');
    }

    public function checkout(Request $request): JsonResponse
    {
        $data = $request->validate([
            'customer_email' => ['required', 'email', 'max:160'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'fail_payment' => ['sometimes', 'boolean'],
        ]);

        try {
            return response()->json($this->service->legacyCheckoutWithPayment($data), 201)
                ->header('X-Backend-Version', 'before');
        } catch (RuntimeException $exception) {
            return response()->json([
                'version' => 'before',
                'requirement' => 8,
                'message' => $exception->getMessage(),
                'partial_state_possible' => true,
            ], 402)->header('X-Backend-Version', 'before');
        }
    }

    public function benchmarkProducts(Request $request): JsonResponse
    {
        return response()->json($this->service->legacyBenchmarkProducts($this->limit($request, 100)))
            ->header('X-Backend-Version', 'before')
            ->header('X-Benchmark-Bottleneck', 'direct-database-scan');
    }

    private function limit(Request $request, int $max): int
    {
        $validated = validator($request->query(), [
            'limit' => ['sometimes', 'integer', 'min:1', "max:{$max}"],
        ])->validate();

        return (int) ($validated['limit'] ?? 20);
    }
}
