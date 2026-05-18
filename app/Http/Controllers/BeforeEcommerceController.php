<?php

namespace App\Http\Controllers;

use App\Services\EcommerceNfrService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    private function limit(Request $request, int $max): int
    {
        $validated = validator($request->query(), [
            'limit' => ['sometimes', 'integer', 'min:1', "max:{$max}"],
        ])->validate();

        return (int) ($validated['limit'] ?? 20);
    }
}
