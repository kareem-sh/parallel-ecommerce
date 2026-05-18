<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class CapacityLimiterMiddleWare
{
    public function handle(Request $request, Closure $next, int $maxConcurrent = 20): Response
    {
        $key = 'capacity:api:active';
        $lock = Cache::lock('lock:' . $key, 5);

        $lock->block(2, function () use ($key, $maxConcurrent) {
            $active = (int) Cache::get($key, 0);

            abort_if($active >= $maxConcurrent, 503, 'Server capacity is currently full. Please retry shortly.');

            Cache::put($key, $active + 1, now()->addSeconds(30));
        });

        try {
            return $next($request);
        } finally {
            $lock->block(2, function () use ($key) {
                $active = max(0, (int) Cache::get($key, 1) - 1);

                Cache::put($key, $active, now()->addSeconds(30));
            });
        }
    }
}
