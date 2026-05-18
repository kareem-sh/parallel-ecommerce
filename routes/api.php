<?php

use App\Http\Controllers\AfterEcommerceController;
use App\Http\Controllers\AfterReportController;
use App\Http\Controllers\BeforeEcommerceController;
use App\Http\Controllers\BeforeReportController;
use App\Http\Controllers\NfrHealthController;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\RateLimiter;


Route::get('/health', NfrHealthController::class);

Route::prefix('before')->group(function () {
    Route::get('/products', [BeforeEcommerceController::class, 'products']);
    Route::post('/products', [BeforeEcommerceController::class, 'createProduct']);
    Route::get('/orders', [BeforeEcommerceController::class, 'orders']);
    Route::post('/orders', [BeforeEcommerceController::class, 'createOrder']);
    Route::get('/reports/daily-sales', [BeforeReportController::class, 'dailySales']);
});

// Use throttle:concurrent for concurrent request limiting
Route::prefix('after')->middleware(['throttle:concurrent'])->group(function () {
    Route::get('/products', [AfterEcommerceController::class, 'products']);
    Route::post('/products', [AfterEcommerceController::class, 'createProduct']);
    Route::get('/orders', [AfterEcommerceController::class, 'orders']);
    Route::post('/orders', [AfterEcommerceController::class, 'createOrder']);
    Route::post('/reports/daily-sales', [AfterReportController::class, 'queueDailySales']);
    Route::get('/reports/daily-sales', [AfterReportController::class, 'dailySalesStatus']);
});
