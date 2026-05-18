<?php

namespace App\Http\Controllers;

use App\Jobs\BuildDailySalesSummaryJob;
use App\Models\DailySalesSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AfterReportController extends Controller
{
    public function queueDailySales(Request $request): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());

        BuildDailySalesSummaryJob::dispatch($date);

        return response()->json([
            'version' => 'after',
            'solution' => 'Queues a chunked background job and returns immediately.',
            'date' => $date,
            'status' => 'queued',
        ], 202)->header('X-Backend-Version', 'after');
    }

    public function dailySalesStatus(Request $request): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());
        $summary = DailySalesSummary::query()
            ->whereDate('sales_date', $date)
            ->first();

        return response()->json([
            'version' => 'after',
            'date' => $date,
            'ready' => $summary !== null,
            'data' => $summary,
        ])->header('X-Backend-Version', 'after');
    }
}
