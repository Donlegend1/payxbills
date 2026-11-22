<?php

namespace App\Http\Controllers;

use App\Models\Occupancy;
use App\Models\Payment;
use App\Models\PaymentAttachment;
use App\Services\BillingService;
use App\Services\Messenger;
use App\Services\Metrics;
use App\Services\PaymentProof;
use App\Support\StoredFile;
use App\Tenancy\CompanyRules;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Online payments confirm themselves through Paystack. Manual payments (cash, transfer, POS, cheque)
 * wait here until an accountant clears them with a reference and proof of payment.
 */
class PaymentController extends Controller
{
    public function index(Request $request, Metrics $metrics): Response
    {
        $user = $request->user();
        $filters = $request->only('status', 'q');
        $month = today()->startOfMonth();

        $payments = Payment::query()
            ->visibleTo($user)
            ->with(['occupancy.user:id,name', 'occupancy.facility:id,name', 'occupancy.space:id,number,label', 'recorder:id,name,role', 'allocations.invoice:id,period', 'attachments:id,payment_id,original_name'])
            // Online checkouts that were never paid are noise after a day
            ->where(fn (Builder $q) => $q->where('status', '!=', 'pending')->orWhere('created_at', '>=', now()->subDay()))
            ->when($filters['status'] ?? null, fn (Builder $q, $s) => $q->where('status', $s))
            ->when($filters['q'] ?? null, fn (Builder $q, $s) => $q->where(fn (Builder $w) => $w
                ->where('reference', 'like', "%{$s}%")
                ->orWhere('receipt_number', 'like', "%{$s}%")
                ->orWhere('gateway_reference', 'like', "%{$s}%")
                ->orWhereHas('occupancy', fn (Builder $o) => $o->where('code', 'like', "%{$s}%")
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$s}%")))))
            ->orderByRaw("status = 'under_review' desc")
            ->latest('paid_on')
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Payment $p) => [
                'id' => $p->id,
                'date' => $p->paid_on->format('j M Y'),
                'client' => $p->occupancy->user->name,
                'place' => $p->occupancy->placeName(),
                'amount' => (float) $p->amount,
                'months' => $p->months,
                'method' => Payment::METHODS[$p->method],
                'source' => $p->source,
                'source_label' => $p->sourceLabel(),
                'reference' => $p->reference,
                'status' => Payment::STATUSES[$p->status],
                'status_key' => $p->status,
                'receipt' => $p->receipt_number,
                'covers' => $p->allocations->map(fn ($a) => $a->invoice->periodLabel())->unique()->values()->all(),
                'attachments' => $p->attachments->map(fn ($a) => ['id' => $a->id, 'name' => $a->original_name]),
                'rejection_reason' => $p->rejection_reason,
            ]);

        $confirmed = $metrics->collected($month);
        $online = (float) Payment::confirmed()->where('source', 'online')
            ->whereBetween('paid_on', [$month, $month->copy()->endOfMonth()])->sum('amount');

        return Inertia::render('App/Payments', [
            'payments' => $payments,
            'filters' => $filters,
            'methods' => array_intersect_key(Payment::METHODS, array_flip(Payment::MANUAL_METHODS)),
            // Facility users this user may record payments for
            'occupancies' => $user->can('record-payments')
                ? Occupancy::active()->visibleTo($user)
                    ->with(['user:id,name', 'facility:id,name', 'space:id,number,label', 'category:id,monthly_amount'])
                    ->withSum(['invoices as open_amount' => fn ($q) => $q->open()], 'amount')
                    ->withSum(['invoices as open_paid' => fn ($q) => $q->open()], 'amount_paid')
                    ->get()
                    ->sortBy(fn (Occupancy $o) => $o->user->name)
                    ->map(fn (Occupancy $o) => [
                        'id' => $o->id,
                        'name' => "{$o->user->name} – {$o->placeName()}",
                        'monthly' => $o->monthlyAmount(),
                        'outstanding' => round((float) $o->open_amount - (float) $o->open_paid, 2),
                    ])
                    ->values()
                : [],
            'kpis' => [
                'awaiting' => Payment::visibleTo($user)->where('status', 'under_review')->count(),
                'confirmed' => $confirmed,
                'rate' => $metrics->collectionRate($month),
                'onlineShare' => $confirmed > 0 ? (int) round($online / $confirmed * 100) : 0,
            ],
        ]);
    }

    /**
     * Staff record a manual payment. It always waits for an accountant to clear it.
     */
    public function store(Request $request, BillingService $billing, PaymentProof $proof): RedirectResponse
    {
        $user = $request->user();

        $data = $request->validate([
            'occupancy_id' => ['required', CompanyRules::exists('occupancies')],
            'months' => ['nullable', 'integer', 'min:1', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(Payment::MANUAL_METHODS)],
            'reference' => ['nullable', 'string', 'max:100'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'files' => ['nullable', 'array', 'max:5'],
            'files.*' => PaymentProof::RULES,
        ]);

        $occupancy = Occupancy::active()->visibleTo($user)->findOrFail($data['occupancy_id']);
        $payment = $billing->recordManualPayment($occupancy, $data, $user);
        $proof->store($payment, $data['files'] ?? [], $user);

        return back()->with('success', 'Payment recorded. It counts once an accountant clears it with the reference and proof.');
    }

    /**
     * Add proof to a payment that is still waiting to be cleared.
     */
    public function attach(Request $request, Payment $payment, PaymentProof $proof): RedirectResponse
    {
        $this->authorizeVisible($request, $payment);

        if ($payment->status !== 'under_review') {
            return back()->with('error', 'Only payments awaiting clearance can take new attachments.');
        }

        $data = $request->validate(['files' => ['required', 'array', 'min:1', 'max:5'], 'files.*' => PaymentProof::RULES]);
        $proof->store($payment, $data['files'], $request->user());

        return back()->with('success', 'Proof attached.');
    }

    /**
     * Accountant clears a manual payment: reference required, proof attached now or already on file.
     */
    public function clear(Request $request, Payment $payment, BillingService $billing, PaymentProof $proof, Messenger $messenger): RedirectResponse
    {
        $data = $request->validate([
            'reference' => ['required', 'string', 'max:100'],
            'files' => [Rule::requiredIf(! $payment->attachments()->exists()), 'array', 'max:5'],
            'files.*' => PaymentProof::RULES,
        ], ['files.required' => 'Attach proof of payment (teller, transfer receipt or cheque scan).']);

        if ($payment->status !== 'under_review') {
            return back()->with('error', 'This payment is not waiting to be cleared.');
        }

        try {
            $proof->store($payment, $data['files'] ?? [], $request->user());
            $billing->clearManualPayment($payment, $request->user(), $data['reference']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $messenger->receipt($payment->refresh());

        return back()->with('success', "Cleared. Receipt {$payment->receipt_number} sent to {$payment->occupancy->user->name}.");
    }

    public function reject(Request $request, Payment $payment, BillingService $billing): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $billing->rejectPayment($payment, $request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Rejected. The reason is shown to whoever recorded it and to the facility user.');
    }

    /**
     * View or download proof of payment: staff who can see the payment, or the facility user it belongs to.
     */
    public function attachment(Request $request, PaymentAttachment $attachment): StreamedResponse
    {
        $user = $request->user();
        abort_unless($user->can('view-payments') || $user->can('use-portal'), 403);
        $this->authorizeVisible($request, $attachment->payment);

        // Opens in the browser; ?download=1 saves it instead
        return StoredFile::respond($request, PaymentAttachment::DISK, $attachment->path, $attachment->original_name);
    }

    protected function authorizeVisible(Request $request, Payment $payment): void
    {
        abort_unless(Payment::visibleTo($request->user())->whereKey($payment->id)->exists(), 403);
    }
}
