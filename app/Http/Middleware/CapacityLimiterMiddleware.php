<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Support\NfrLogger;
use Illuminate\Support\Facades\Redis;
use Symfony\Component\HttpFoundation\Response;

class CapacityLimiterMiddleware
{
    private const KEY_PREFIX = 'nfr:capacity:';

    private const MAX_SIMULATE_MS = 2000;

    public function handle(
        Request $request,
        Closure $next,
        int $maxConcurrent = 25
    ): Response {
        $key = self::KEY_PREFIX.str_replace('/', '_', $request->path());
        $redis = Redis::connection();
        $active = (int) $redis->incr($key);

        if ($active === 1) {
            $redis->expire($key, 30);
        }

        if ($active > $maxConcurrent) {
            $redis->decr($key);

            NfrLogger::error('capacity_limiter_rejected', [
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
            $this->simulateInFlightDelay($request);

            return $next($request);
        } finally {
            $redis->decr($key);
        }
    }

    /**
     * Optional ?simulate_ms= query param keeps admitted requests in-flight
     * so concurrent bursts overlap and excess callers get 503 (requirement 2 k6 test).
     */
    private function simulateInFlightDelay(Request $request): void
    {
        $milliseconds = min(self::MAX_SIMULATE_MS, max(0, (int) $request->query('simulate_ms', 0)));

        if ($milliseconds > 0) {
            usleep($milliseconds * 1000);
        }
    }
}
