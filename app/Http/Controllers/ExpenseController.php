<?php

namespace App\Http\Controllers;

use App\Models\Expense;
use App\Models\ExpenseLine;
use App\Models\Facility;
use App\Services\Metrics;
use App\Support\StoredFile;
use App\Tenancy\CompanyRules;
use App\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Expense memos: raised with one or more categories and a picture of the memo, then approved,
 * partially approved, kept in view (KIV) or rejected. Only approved amounts count as spend.
 */
class ExpenseController extends Controller
{
    /** Most categories on one memo. */
    protected const MAX_LINES = 10;

    public function index(Request $request, Metrics $metrics): Response
    {
        $user = $request->user();
        $month = $metrics->month($request->query('month'));
        $range = [$month->copy()->startOfMonth(), $month->copy()->endOfMonth()];
        $filters = $request->only('status', 'month');

        $memos = Expense::query()
            ->visibleTo($user)
            ->with(['facility:id,name', 'requester:id,name', 'reviewer:id,name', 'lines'])
            ->whereBetween('spent_on', $range)
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByRaw("status in ('pending', 'kiv') desc")
            ->latest('spent_on')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Expense $e) => [
                'id' => $e->id,
                'memo' => $e->memo_number,
                'date' => $e->spent_on->format('j M'),
                'description' => $e->description,
                'facility' => $e->facility?->name ?? '—',
                'amount' => (float) $e->amount,
                'approved_amount' => $e->approved_amount === null ? null : (float) $e->approved_amount,
                'status' => $e->statusLabel(),
                'status_key' => $e->status,
                'reviewable' => $e->isReviewable(),
                'requested_by' => $e->requester?->name,
                'reviewed_by' => $e->reviewer?->name,
                'review_note' => $e->review_note,
                'picture' => $e->memo_path ? route('expenditure.memo', $e) : null,
                'lines' => $e->lines->map(fn (ExpenseLine $l) => [
                    'id' => $l->id,
                    'category' => $l->category,
                    'amount' => (float) $l->amount,
                    'approved_amount' => $l->approved_amount === null ? null : (float) $l->approved_amount,
                ]),
            ]);

        $recorded = fn () => ExpenseLine::recorded()->whereHas('expense', fn (Builder $q) => $q->visibleTo($user)->whereBetween('spent_on', $range));
        $approved = Expense::visibleTo($user)->approved()->whereBetween('spent_on', $range);

        // Dues billed this month against approved spend, per facility
        $spend = ExpenseLine::recorded()
            ->join('expenses', 'expenses.id', '=', 'expense_lines.expense_id')
            ->whereBetween('expenses.spent_on', $range)
            ->whereNotNull('expenses.facility_id')
            ->selectRaw('expenses.facility_id, sum(expense_lines.approved_amount) as total')
            ->groupBy('expenses.facility_id')
            ->pluck('total', 'facility_id');

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

        $waiting = Expense::visibleTo($user)->reviewable();

        return Inertia::render('App/Expenditure', [
            'memos' => $memos,
            'filters' => $filters,
            'statuses' => Expense::STATUSES,
            'month' => $month->format('Y-m'),
            'monthLabel' => $month->format('F Y'),
            'months' => $metrics->monthOptions(),
            'categories' => Expense::CATEGORIES,
            'byCategory' => $recorded()->selectRaw('category, sum(approved_amount) as total')->groupBy('category')->orderByDesc('total')
                ->get()->map(fn ($r) => [$r->category, (float) $r->total]),
            'facilitySpend' => $facilitySpend,
            'facilities' => $facilities,
            'kpis' => [
                'approved' => (float) $recorded()->sum('approved_amount'),
                'approvedCount' => (clone $approved)->count(),
                'pending' => (float) (clone $waiting)->sum('amount'),
                'pendingCount' => (clone $waiting)->count(),
                'income' => (float) $billed->sum(),
            ],
        ]);
    }

    /**
     * Raise a memo. One category by default; several when the memo covers more than one,
     * each with its own amount. The requested amount is their total.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'spent_on' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:255'],
            'facility_id' => ['nullable', CompanyRules::exists('facilities')],
            'lines' => ['required', 'array', 'min:1', 'max:'.self::MAX_LINES],
            'lines.*.category' => ['required', 'distinct', Rule::in(Expense::CATEGORIES)],
            'lines.*.amount' => ['required', 'numeric', 'gt:0'],
            'memo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
        ], [
            'lines.*.category.distinct' => 'Each category can only appear once on a memo.',
            'memo.mimes' => 'The memo picture must be a photo (JPG, PNG, WebP) or a PDF.',
        ]);

        DB::transaction(function () use ($data, $request) {
            $lines = collect($data['lines']);

            $expense = Expense::create([
                'spent_on' => $data['spent_on'],
                'description' => $data['description'],
                'facility_id' => $data['facility_id'] ?? null,
                'category' => $lines->count() === 1 ? $lines[0]['category'] : 'Multiple',
                'amount' => round($lines->sum(fn ($l) => (float) $l['amount']), 2),
                'status' => 'pending',
                'requested_by' => $request->user()->id,
            ]);

            $lines->each(fn ($l) => $expense->lines()->create(['category' => $l['category'], 'amount' => $l['amount']]));

            if ($file = $request->file('memo')) {
                $expense->update([
                    'memo_path' => $file->store("memos/{$expense->id}", Expense::DISK),
                    'memo_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                ]);
            }
        });

        return back()->with('success', 'Memo submitted for approval.');
    }

    /**
     * Approve, partially approve, keep in view or reject a memo.
     * For a partial approval, each category gets an approved amount (blank = not approved).
     */
    public function review(Request $request, Expense $expense): RedirectResponse
    {
        $data = $request->validate([
            'decision' => ['required', Rule::in(['approved', 'partially_approved', 'kiv', 'rejected'])],
            'note' => [Rule::requiredIf(in_array($request->input('decision'), ['kiv', 'rejected'], true)), 'nullable', 'string', 'max:255'],
            'lines' => [Rule::requiredIf($request->input('decision') === 'partially_approved'), 'array'],
            'lines.*.id' => ['required', 'integer'],
            'lines.*.approved_amount' => ['nullable', 'numeric', 'min:0'],
        ], ['note.required' => 'Give a reason so the person who raised the memo knows why.']);

        if (! $expense->isReviewable()) {
            return back()->with('error', 'This memo has already been decided.');
        }

        DB::transaction(function () use ($expense, $data, $request) {
            $lines = $expense->lines()->get();
            $decision = $data['decision'];

            if ($decision === 'approved') {
                $lines->each(fn (ExpenseLine $l) => $l->update(['approved_amount' => $l->amount]));
            } elseif ($decision === 'partially_approved') {
                $decision = $this->approveSome($lines, collect($data['lines'])->keyBy('id'));
            } else {
                $lines->each(fn (ExpenseLine $l) => $l->update(['approved_amount' => null]));
            }

            $approved = in_array($decision, Expense::RECORDED, true) ? round((float) $expense->lines()->sum('approved_amount'), 2) : null;

            $expense->update([
                'status' => $decision,
                'approved_amount' => $approved,
                'reviewed_by' => $request->user()->id,
                'reviewed_at' => now(),
                'review_note' => $data['note'] ?? null,
            ]);
        });

        $expense->refresh();

        return back()->with('success', "Memo {$expense->memo_number}: {$expense->statusLabel()}.");
    }

    public function approve(Request $request, Expense $expense): RedirectResponse
    {
        return $this->review($request->merge(['decision' => 'approved']), $expense);
    }

    public function reject(Request $request, Expense $expense): RedirectResponse
    {
        return $this->review($request->merge(['decision' => 'rejected', 'note' => $request->input('note', 'Rejected')]), $expense);
    }

    /**
     * The picture of the memo, in the browser (or ?download=1).
     */
    public function memo(Request $request, Expense $expense): StreamedResponse
    {
        abort_unless($expense->memo_path && Expense::visibleTo($request->user())->whereKey($expense->id)->exists(), 404);

        return StoredFile::respond($request, Expense::DISK, $expense->memo_path, $expense->memo_name ?? 'memo');
    }

    /**
     * Set each line's approved amount from the reviewer's input. Returns the resulting status:
     * a "partial" approval that approves everything in full is simply approved.
     */
    protected function approveSome($lines, $input): string
    {
        $any = false;
        $full = true;

        foreach ($lines as $line) {
            $amount = $input[$line->id]['approved_amount'] ?? null;
            $amount = $amount === null || $amount === '' || (float) $amount <= 0 ? null : round((float) $amount, 2);

            if ($amount !== null && $amount > (float) $line->amount) {
                throw ValidationException::withMessages(['lines' => "The approved amount for {$line->category} cannot be more than was requested."]);
            }

            $line->update(['approved_amount' => $amount]);
            $any = $any || $amount !== null;
            $full = $full && $amount !== null && $amount == (float) $line->amount;
        }

        if (! $any) {
            throw ValidationException::withMessages(['lines' => 'Approve at least one category, or reject the memo.']);
        }

        return $full ? 'approved' : 'partially_approved';
    }
}
