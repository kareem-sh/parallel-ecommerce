<?php

namespace App\Http\Middleware;

use App\Support\NfrLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestCorrelationMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->headers->get('X-Request-Id', (string) Str::uuid());
        $started = microtime(true);

        $request->headers->set('X-Request-Id', $requestId);

        /** @var Response $response */
        $response = $next($request);

        $durationMs = round((microtime(true) - $started) * 1000, 2);
        $response->headers->set('X-Request-Id', $requestId);

        if (str_starts_with($request->path(), 'api/')) {
            $context = [
                'request_id' => $requestId,
                'method' => $request->method(),
                'path' => $request->path(),
                'status' => $response->getStatusCode(),
                'duration_ms' => $durationMs,
                'ip' => $request->ip(),
            ];

            if ($response->getStatusCode() >= 400) {
                NfrLogger::error('nfr_request_completed', $context);
            } else {
                NfrLogger::success('nfr_request_completed', $context);
            }
        }

        return $response;
    }
}
