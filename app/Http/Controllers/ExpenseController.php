<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\Facility;
use App\Services\Metrics;
use App\Tenancy\CompanyRules;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class ExpenseController extends Controller
{
    public function index(Request $request, Metrics $metrics): Response
    {
        $user = $request->user();
        $month = $metrics->month($request->query('month'));
        $range = [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];
        $filters = $request->only('status', 'month');

        $memos = Expense::query()
            ->visibleTo($user)
            ->with(['facility:id,name', 'requester:id,name'])
            ->whereBetween('spent_on', $range)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("status = 'pending' desc")
            ->latest('spent_on')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Expense $e) => [
                'id' => $e->id,
                'memo' => $e->memo_number,
                'date' => $e->spent_on->format('j M'),
                'description' => $e->description,
                'category' => $e->category,
                'facility' => $e->facility?->name ?? '—',
                'amount' => (float) $e->amount,
                'status' => ucfirst($e->status),
                'requested_by' => $e->requester?->name,
            ]);

        $approved = Expense::visibleTo($user)->approved()->whereBetween('spent_on', $range);

        // Dues billed this month against approved spend, per facility
        $spend = Expense::approved()->whereBetween('spent_on', $range)->whereNotNull('facility_id')
            ->selectRaw('facility_id, sum(amount) as total')->groupBy('facility_id')->pluck('total', 'facility_id');

        $billed = DB::table('invoices')
            ->join('occupancies', 'occupancies.id', '=', 'invoices.occupancy_id')
            ->where('invoices.company_id', CurrentCompany::id())
            ->where('invoices.period', $month->format('Y-m'))
            ->where('invoices.status', '!=', 'cancelled')
            ->selectRaw('occupancies.facility_id, sum(invoices.amount) as total')
            ->groupBy('occupancies.facility_id')
            ->pluck('total', 'facility_id');

        $facilities = Facility::visibleTo($user)->orderBy('name')->get(['id', 'name']);

        $facilitySpend = $facilities
            ->map(fn (Facility $f) => ['name' => $f->name, 'income' => (float) ($billed[$f->id] ?? 0), 'spend' => (float) ($spend[$f->id] ?? 0)])
            ->filter(fn ($r) => $r['income'] > 0 || $r['spend'] > 0)
            ->sortByDesc('income')
            ->take(10)
            ->values();

        return Inertia::render('App/Expenditure', [
            'memos' => $memos,
            'filters' => $filters,
            'month' => $month->format('Y-m'),
            'monthLabel' => $month->format('F Y'),
            'months' => $metrics->monthOptions(),
            'categories' => Expense::CATEGORIES,
            'byCategory' => (clone $approved)->selectRaw('category, sum(amount) as total')->groupBy('category')->orderByDesc('total')
                ->get()->map(fn ($r) => [$r->category, (float) $r->total]),
            'facilitySpend' => $facilitySpend,
            'facilities' => $facilities,
            'kpis' => [
                'approved' => (float) (clone $approved)->sum('amount'),
                'approvedCount' => (clone $approved)->count(),
                'pending' => (float) Expense::visibleTo($user)->where('status', 'pending')->sum('amount'),
                'pendingCount' => Expense::visibleTo($user)->where('status', 'pending')->count(),
                'income' => (float) $billed->sum(),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:255'],
            'category' => ['required', Rule::in(Expense::CATEGORIES)],
            'facility_id' => ['nullable', CompanyRules::exists('facilities')],
            'amount' => ['required', 'numeric', 'gt:0'],
        ]);

        Expense::create($data + ['status' => 'pending', 'requested_by' => $request->user()->id]);

        return back()->with('success', 'Memo submitted for approval.');
    }

    public function approve(Request $request, Expense $expense): RedirectResponse
    {
        return $this->review($request, $expense, 'approved');
    }

    public function reject(Request $request, Expense $expense): RedirectResponse
    {
        return $this->review($request, $expense, 'rejected');
    }

    protected function review(Request $request, Expense $expense, string $status): RedirectResponse
    {
        if ($expense->status !== 'pending') {
            return back()->with('error', 'This memo has already been reviewed.');
        }

        $expense->update(['status' => $status, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);

        return back()->with('success', "Memo {$expense->memo_number} {$status}.");
    }
}
