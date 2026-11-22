<?php

namespace App\Http\Controllers;

use App\Services\Metrics;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsController extends Controller
{
    public function __invoke(Request $request, Metrics $metrics): Response
    {
        $month = $metrics->month($request->query('month'));
        $expected = $metrics->expected();
        $billed = $metrics->billed($month);

        return Inertia::render('App/Analytics', [
            'month' => $month->format('Y-m'),
            'monthLabel' => $month->format('F Y'),
            'months' => $metrics->monthOptions(),
            'kpis' => [
                'expected' => $expected,
                'billed' => $billed,
                'billedShare' => $expected > 0 ? (int) round($billed / $expected * 100) : 0,
                'collected' => $metrics->collected($month),
                'rate' => $metrics->collectionRate($month),
                'daysToPay' => $metrics->averageDaysToPay(),
            ],
            'trend' => $metrics->trend($month),
            'byMethod' => $metrics->collectedByMethod($month),
            'byFacility' => $metrics->collectedByFacility($month),
            'byCategory' => $metrics->collectedByCategory($month),
            'clientStatus' => $metrics->occupancyStatus(),
        ]);
    }
}
