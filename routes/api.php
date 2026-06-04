<?php

use App\Http\Controllers\AfterEcommerceController;
use App\Http\Controllers\AfterReportController;
use App\Http\Controllers\BeforeEcommerceController;
use App\Http\Controllers\BeforeReportController;
use App\Http\Controllers\NfrHealthController;
use Illuminate\Support\Facades\Route;

Route::get('/health', NfrHealthController::class);

Route::prefix('before')->group(function () {
    Route::get('/products', [BeforeEcommerceController::class, 'products']);
    Route::get('/hot-products', [BeforeEcommerceController::class, 'hotProducts']);
    Route::post('/products', [BeforeEcommerceController::class, 'createProduct']);
    Route::post('/products/{product}/stock-adjust', [BeforeEcommerceController::class, 'adjustStock']);
    Route::get('/orders', [BeforeEcommerceController::class, 'orders']);
    Route::post('/orders', [BeforeEcommerceController::class, 'createOrder']);
    Route::post('/checkout', [BeforeEcommerceController::class, 'checkout']);
    Route::get('/benchmarks/products', [BeforeEcommerceController::class, 'benchmarkProducts']);
    Route::get('/reports/daily-sales', [BeforeReportController::class, 'dailySales']);
});

Route::prefix('after')->middleware(['capacity:25'])->group(function () {
    Route::get('/products', [AfterEcommerceController::class, 'products']);
    Route::get('/hot-products', [AfterEcommerceController::class, 'hotProducts']);
    Route::post('/products', [AfterEcommerceController::class, 'createProduct']);
    Route::post('/products/{product}/stock-adjust', [AfterEcommerceController::class, 'adjustStock']);
    Route::get('/orders', [AfterEcommerceController::class, 'orders']);
    Route::post('/orders', [AfterEcommerceController::class, 'createOrder']);
    Route::post('/checkout', [AfterEcommerceController::class, 'checkout']);
    Route::get('/benchmarks/products', [AfterEcommerceController::class, 'benchmarkProducts']);
    Route::post('/reports/daily-sales', [AfterReportController::class, 'queueDailySales']);
    Route::get('/reports/daily-sales', [AfterReportController::class, 'dailySalesStatus']);
});
