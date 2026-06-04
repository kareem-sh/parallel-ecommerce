<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class CapacityLimiterMiddleware
{
    private const KEY_PREFIX = 'nfr:capacity:';

    public function handle(
        Request $request,
        Closure $next,
        int $maxConcurrent = 25
    ): Response {
        Log::channel('nfr')->info('capacity_limiter_entered', [
            'path' => $request->path(),
            'max' => $maxConcurrent,
            'ip' => $request->ip(),
        ]);

        $key = self::KEY_PREFIX.str_replace('/', '_', $request->path());
        $redis = Redis::connection();
        $active = (int) $redis->incr($key);

        if ($active === 1) {
            $redis->expire($key, 30);
        }

        Log::channel('nfr')->info('capacity_limiter_counted_request', [
            'key' => $key,
            'active' => $active,
            'max' => $maxConcurrent,
        ]);

        if ($active > $maxConcurrent) {
            $redis->decr($key);

            Log::channel('nfr')->warning('capacity_limiter_rejected_request', [
                'active' => $active,
                'max' => $maxConcurrent,
            ]);

            return response()->json([
                'message' => 'Server capacity is currently full. Please retry shortly.',
                'active_requests' => $active - 1,
                'max_capacity' => $maxConcurrent,
            ], 503)->header('Retry-After', '1');
        }

        try {
            return $next($request);
        } finally {
            $remaining = (int) $redis->decr($key);
            Log::channel('nfr')->info('capacity_limiter_released_request', [
                'key' => $key,
                'remaining' => max(0, $remaining),
            ]);
        }
    }
}
