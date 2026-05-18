<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class CapacityLimiter
{
    public function handle(
        Request $request,
        Closure $next,
        int $maxConcurrent = 50
    ): Response {

        // Log that middleware is running
        Log::info('CAPACITY: Middleware triggered', [
            'path' => $request->path(),
            'max' => $maxConcurrent,
            'ip' => $request->ip()
        ]);

        $key = 'capacity:' . str_replace('/', '_', $request->path());

        // Atomic increment
        $active = Cache::increment($key);

        Log::info('CAPACITY: Current count', [
            'key' => $key,
            'active' => $active,
            'max' => $maxConcurrent
        ]);

        // Set expiry on first increment
        if ($active === 1) {
            Cache::expire($key, 30);
        }

        // Check capacity
        if ($active > $maxConcurrent) {
            Cache::decrement($key);

            Log::warning('CAPACITY: Rejected request', [
                'active' => $active,
                'max' => $maxConcurrent
            ]);

            return response()->json([
                'message' => 'Server capacity is currently full. Please retry shortly.',
                'active_requests' => $active - 1,
                'max_capacity' => $maxConcurrent,
            ], 503);
        }

        try {
            $response = $next($request);
            Log::info('CAPACITY: Request completed successfully');
            return $response;
        } finally {
            $remaining = Cache::decrement($key);
            Log::info('CAPACITY: Decremented counter', ['remaining' => $remaining]);
        }
    }
}
