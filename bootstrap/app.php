<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\RequestCorrelationMiddleware;
use App\Http\Middleware\CapacityLimiterMiddleware;
use Iamfarhad\Prometheus\Support\PrometheusMiddlewareHelper;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        PrometheusMiddlewareHelper::register($middleware);
        $middleware->append(RequestCorrelationMiddleware::class);
        $middleware->alias([
            'capacity' => CapacityLimiterMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
