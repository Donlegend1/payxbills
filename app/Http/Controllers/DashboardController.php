<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Invoice;
use App\Models\Occupancy;
use App\Models\Payment;
use App\Models\RemovalRequest;
use App\Services\Metrics;
use App\Support\Money;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(Request $request, Metrics $metrics): Response|RedirectResponse
    {
        $user = $request->user();

        // The super admin sees all companies until they open one
        if ($user->isPlatformAdmin() && ! CurrentCompany::id()) {
            return redirect()->route('platform.companies');
        }

        // Facility users have their own portal; supervisors work from the facility users list
        if ($user->can('use-portal')) {
            return redirect()->route('portal');
        }

        if ($user->cannot('view-dashboard')) {
            return redirect()->route('facility-users');
        }

        $month = $metrics->month($request->query('month'));
        $billed = $metrics->billed($month);
        $collected = $metrics->collected($month);
        $overdue = $metrics->overdueInvoices();
        $status = $metrics->occupancyStatus();

        return Inertia::render('App/Dashboard', [
            'month' => $month->format('Y-m'),
            'monthLabel' => $month->format('F Y'),
            'months' => $metrics->monthOptions(),
            'kpis' => [
                'clients' => Occupancy::active()->count(),
                'billed' => $billed,
                'invoices' => $metrics->invoiceCount($month),
                'collected' => $collected,
                'rate' => $metrics->collectionRate($month),
                'outstanding' => $metrics->outstanding(),
                'openInvoices' => $metrics->openInvoices()->count(),
                'overdue' => round($overdue->sum(fn (Invoice $i) => $i->balance()), 2),
                'overdueInvoices' => $overdue->count(),
            ],
            'trend' => $metrics->trend($month),
            'aging' => $metrics->aging(),
            'topClients' => $metrics->agingByOccupancy()->take(5)->values(),
            'recentPayments' => Payment::with('occupancy.user:id,name')->where('status', '!=', 'pending')->latest('paid_on')->latest('id')->limit(5)->get()
                ->map(fn (Payment $p) => [
                    'id' => $p->id,
                    'date' => $p->paid_on->format('j M'),
                    'client' => $p->occupancy->user->name,
                    'amount' => (float) $p->amount,
                    'method' => Payment::METHODS[$p->method],
                    'status' => Payment::STATUSES[$p->status],
                ]),
            'attention' => [
                ['Removal requests awaiting approval', RemovalRequest::where('status', 'pending')->count(), 'facility-users'],
                ['Manual payments awaiting clearance', Payment::where('status', 'under_review')->count(), 'payments'],
                ['Facility users overdue > 90 days', $overdue->filter(fn (Invoice $i) => $i->daysOverdue() > 90)->unique('occupancy_id')->count(), 'receivables'],
                ['Expense memos awaiting a decision (pending or KIV)', Expense::reviewable()->count(), 'expenditure'],
            ],
            'clientStatus' => $status,
            'insights' => [
                'rate' => $metrics->collectionRate($month),
                'daysToPay' => $metrics->averageDaysToPay(),
                'unpaid' => $metrics->openInvoices()->count(),
                'atRisk' => $status['red'],
            ],
            'expected' => Money::format($metrics->expected()),
        ]);
    }
}
