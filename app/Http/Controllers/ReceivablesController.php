<?php

namespace App\Http\Controllers;

use App\Services\Metrics;
use Inertia\Inertia;
use Inertia\Response;

class ReceivablesController extends Controller
{
    /** Facility users listed by name before the rest are grouped into one row. */
    protected const LISTED = 15;

    public function __invoke(Metrics $metrics): Response
    {
        $byOccupancy = $metrics->agingByOccupancy();
        $rows = $byOccupancy->take(self::LISTED)->values();
        $rest = $byOccupancy->slice(self::LISTED);

        if ($rest->isNotEmpty()) {
            $bands = array_map(fn ($i) => round($rest->sum(fn ($r) => $r['bands'][$i]), 2), range(0, 4));
            $rows->push(['occupancy_id' => null, 'name' => "Other {$rest->count()} facility users", 'place' => null, 'bands' => $bands, 'total' => round(array_sum($bands), 2)]);
        }

        $totals = array_map(fn ($i) => round($byOccupancy->sum(fn ($r) => $r['bands'][$i]), 2), range(0, 4));

        return Inertia::render('App/Receivables', [
            'rows' => $rows,
            'totals' => $totals,
            'colors' => Metrics::BAND_COLORS,
            'kpis' => [
                'outstanding' => $metrics->outstanding(),
                'olderThan60' => round($totals[2] + $totals[3] + $totals[4], 2),
                'open' => $metrics->openInvoices()->count(),
                'daysToPay' => $metrics->averageDaysToPay(),
            ],
        ]);
    }
}
