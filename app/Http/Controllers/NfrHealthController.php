<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

class NfrHealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'app' => true,
            'database' => $this->databaseIsHealthy(),
            'redis' => $this->redisIsHealthy(),
        ];

        $ok = ! in_array(false, $checks, true);

        return response()->json([
            'status' => $ok ? 'ok' : 'degraded',
            'checks' => $checks,
        ], $ok ? 200 : 503);
    }

    private function databaseIsHealthy(): bool
    {
        try {
            DB::select('select 1');

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function redisIsHealthy(): bool
    {
        try {
            return Redis::ping() === true || Redis::ping() === 'PONG';
        } catch (Throwable) {
            return false;
        }
    }
}
