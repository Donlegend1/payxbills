<?php

namespace App\Http\Controllers;

use App\Models\Facility;
use App\Models\Invoice;
use App\Models\MessageLog;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Setting;
use App\Services\BillingService;
use App\Services\Messenger;
use App\Services\Metrics;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Monthly dues. Issued automatically on the billing day (and when a facility user is registered or pays ahead).
 */
class InvoiceController extends Controller
{
    public function index(Request $request, Metrics $metrics): Response
    {
        $filters = $request->only('status', 'q', 'period', 'facility');
        $month = today()->startOfMonth();

        $invoices = Invoice::query()
            ->with(['occupancy:id,code,user_id,facility_id,space_id', 'occupancy.user:id,name', 'occupancy.facility:id,name', 'occupancy.space:id,number,label'])
            ->when($filters['status'] ?? null, function (Builder $q, string $status) {
                match ($status) {
                    'overdue' => $q->overdue(),
                    'unpaid', 'partial' => $q->where('status', $status)->whereDate('due_date', '>=', Invoice::overdueCutoff()),
                    default => $q->where('status', $status),
                };
            })
            ->when($filters['period'] ?? null, fn (Builder $q, $p) => $q->where('period', $p))
            ->when($filters['facility'] ?? null, fn (Builder $q, $f) => $q->whereHas('occupancy', fn (Builder $o) => $o->where('facility_id', $f)))
            ->when($filters['q'] ?? null, fn (Builder $q, string $s) => $q->where(fn (Builder $w) => $w
                ->where('number', 'like', "%{$s}%")
                ->orWhereHas('occupancy', fn (Builder $o) => $o->where('code', 'like', "%{$s}%")
                    ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$s}%")))))
            ->latest('period')
            ->latest('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Invoice $i) => [
                'id' => $i->id,
                'number' => $i->number,
                'client' => $i->occupancy->user->name,
                'place' => $i->occupancy->facility->name.' · '.$i->occupancy->space->name(),
                'month' => $i->periodLabel(),
                'amount' => (float) $i->amount,
                'paid' => (float) $i->amount_paid,
                'due' => $i->due_date->format('j M Y'),
                'status' => $i->displayStatus(),
                'sent' => $i->sent_at !== null,
                'nrs_copy' => $i->nrs_copy,
            ]);

        return Inertia::render('App/Invoices', [
            'invoices' => $invoices,
            'filters' => $filters,
            'months' => $metrics->monthOptions(),
            'facilities' => Facility::orderBy('name')->get(['id', 'name']),
            'nrsEnabled' => (bool) Setting::get('nrs_enabled'),
            'kpis' => [
                'monthLabel' => $month->format('F'),
                'billed' => $metrics->billed($month),
                'invoices' => $metrics->invoiceCount($month),
                'collected' => $metrics->collected($month),
                'rate' => $metrics->collectionRate($month),
                'open' => Invoice::open()->count(),
            ],
        ]);
    }

    public function show(Invoice $invoice): Response
    {
        $invoice->load(['occupancy.user', 'occupancy.facility', 'occupancy.space', 'occupancy.category', 'allocations.payment']);

        return Inertia::render('App/InvoiceShow', [
            'invoice' => $this->detail($invoice),
            'payments' => $invoice->allocations->map(fn (PaymentAllocation $a) => [
                'id' => $a->payment->id,
                'date' => $a->payment->paid_on->format('j M Y'),
                'receipt' => $a->payment->receipt_number,
                'method' => Payment::METHODS[$a->payment->method],
                'amount' => (float) $a->amount,
            ]),
            'messages' => MessageLog::where('invoice_id', $invoice->id)->latest()->get()->map(fn (MessageLog $m) => [
                'time' => $m->created_at->format('j M H:i'),
                'channel' => $m->channel === 'sms' ? 'SMS' : 'Email',
                'type' => ucfirst($m->type),
                'status' => ucfirst($m->status),
            ]),
            'payUrl' => $invoice->occupancy->payUrl(),
        ]);
    }

    /**
     * Run this month's billing now instead of waiting for the scheduler.
     */
    public function generate(BillingService $billing, Messenger $messenger): RedirectResponse
    {
        $issued = $billing->generateMonthly();
        $issued->each(fn (Invoice $i) => $messenger->invoiceIssued($i));

        return back()->with('success', $issued->isEmpty()
            ? "Everyone due for billing already has this month's invoice (or the billing day hasn't arrived)."
            : "{$issued->count()} invoices issued and sent.");
    }

    public function send(Invoice $invoice, Messenger $messenger): RedirectResponse
    {
        $logs = $messenger->invoiceIssued($invoice);

        return back()->with($logs->isEmpty() ? 'error' : 'success', $logs->isEmpty()
            ? 'This facility user has no email or phone number.'
            : "Invoice {$invoice->number} sent.");
    }

    public function cancel(Invoice $invoice, BillingService $billing): RedirectResponse
    {
        try {
            $billing->cancelInvoice($invoice);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Invoice {$invoice->number} cancelled.");
    }

    /**
     * Everything needed to print an invoice.
     */
    protected function detail(Invoice $invoice): array
    {
        $occupancy = $invoice->occupancy;

        return [
            'id' => $invoice->id,
            'number' => $invoice->number,
            'description' => $invoice->description,
            'period' => $invoice->periodLabel(),
            'amount' => (float) $invoice->amount,
            'paid' => (float) $invoice->amount_paid,
            'balance' => $invoice->balance(),
            'issue_date' => $invoice->issue_date->format('j M Y'),
            'due_date' => $invoice->due_date->format('j M Y'),
            'status' => $invoice->displayStatus(),
            'sent_at' => $invoice->sent_at?->format('j M Y H:i'),
            'nrs_copy' => $invoice->nrs_copy,
            'can_cancel' => $invoice->status !== 'cancelled' && (float) $invoice->amount_paid == 0,
            'client' => [
                'name' => $occupancy->user->name,
                'code' => $occupancy->code,
                'email' => $occupancy->user->email,
                'phone' => $occupancy->user->phone,
                'facility' => $occupancy->facility->name,
                'address' => $occupancy->facility->address,
                'space' => $occupancy->space->name(),
                'category' => $occupancy->category->name,
            ],
            'company' => [
                'name' => Setting::get('company_name'),
                'logo' => $invoice->company->logoUrl(),
                'bank_details' => Setting::get('bank_details'),
            ],
        ];
    }
}
