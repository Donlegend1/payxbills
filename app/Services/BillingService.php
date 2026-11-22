<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Occupancy;
use App\Models\Payment;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Monthly dues for facility users: issues one invoice per occupancy per month,
 * plans "pay N months" checkouts, and applies confirmed payments oldest month first.
 */
class BillingService
{
    /**
     * Issue the invoice for one month, unless that month already has one.
     */
    public function issueForPeriod(Occupancy $occupancy, Carbon $month, ?User $by = null): ?Invoice
    {
        $month = $month->copy()->startOfMonth();
        $period = $month->format('Y-m');

        if ($occupancy->invoices()->where('period', $period)->exists()) {
            return null;
        }

        $occupancy->loadMissing(['category', 'facility', 'space']);

        // Nothing to bill: no category (or a category with no amount)
        if ($occupancy->monthlyAmount() <= 0) {
            return null;
        }

        $billingDate = $month->copy()->setDay(min($occupancy->billingDay(), $month->daysInMonth));
        // Months paid ahead are issued today, but still fall due in their own month
        $issue = $billingDate->gt(today()) ? today() : $billingDate;

        $invoice = DB::transaction(function () use ($occupancy, $period, $month, $issue, $billingDate, $by) {
            $invoice = Invoice::create([
                'occupancy_id' => $occupancy->id,
                'period' => $period,
                'description' => trim(($occupancy->categoryName() ?? 'Monthly').' dues – '.$month->format('F Y').($occupancy->placeName() !== '—' ? " ({$occupancy->placeName()})" : '')),
                'amount' => $occupancy->monthlyAmount(),
                'issue_date' => $issue,
                'due_date' => $billingDate->copy()->addDays((int) Setting::get('due_days')),
                'nrs_copy' => (bool) Setting::get('nrs_enabled'),
                'created_by' => $by?->id,
            ]);

            $invoice->update(['number' => sprintf('%s-%s-%06d', Setting::get('invoice_prefix'), str_replace('-', '', $period), $invoice->id)]);

            return $invoice;
        });

        $this->applyCredit($occupancy);

        return $invoice->refresh();
    }

    /**
     * Issue this month's invoice for every active occupancy once the billing day has arrived.
     *
     * @return Collection<int, Invoice>
     */
    public function generateMonthly(?Carbon $today = null): Collection
    {
        $today = ($today ?? today())->copy()->startOfDay();
        $issued = collect();

        // Only facility users the admin has activated
        Occupancy::billing()
            ->with(['category', 'facility', 'space'])
            ->whereDate('start_date', '<=', $today)
            // Clients outside any facility are billed too; those in a closed facility are not
            ->where(fn ($q) => $q->whereNull('facility_id')->orWhereHas('facility', fn ($f) => $f->active()))
            ->each(function (Occupancy $occupancy) use ($today, $issued) {
                // Each client is billed on their own billing day (or the company's)
                if ($today->day < min($occupancy->billingDay(), $today->daysInMonth)) {
                    return;
                }

                if ($invoice = $this->issueForPeriod($occupancy, $today)) {
                    $issued->push($invoice);
                }
            });

        return $issued;
    }

    /**
     * The next $months months a payment would cover, oldest first: unpaid invoices, then months ahead.
     *
     * @return Collection<int, array{period: string, label: string, amount: float, invoice_id: int|null}>
     */
    public function plan(Occupancy $occupancy, int $months): Collection
    {
        $months = max(1, $months);
        $occupancy->loadMissing('category');

        $plan = $occupancy->invoices()->open()->orderBy('period')->get()
            ->map(fn (Invoice $i) => ['period' => $i->period, 'label' => $i->periodLabel(), 'amount' => $i->balance(), 'invoice_id' => $i->id])
            ->take($months);

        $latest = $occupancy->invoices()->max('period');
        $next = $latest
            ? Carbon::createFromFormat('Y-m-d', $latest.'-01')->addMonthNoOverflow()
            : $occupancy->start_date->copy()->startOfMonth();

        while ($plan->count() < $months) {
            $plan->push([
                'period' => $next->format('Y-m'),
                'label' => $next->format('F Y'),
                'amount' => $occupancy->monthlyAmount(),
                'invoice_id' => null,
            ]);
            $next->addMonthNoOverflow();
        }

        return $plan->values();
    }

    /**
     * Choices for "pay N months": 1 up to the max_months setting, each with its total and the month it pays up to.
     *
     * @return list<array{months: int, amount: float, through: string, label: string}>
     */
    public function payOptions(Occupancy $occupancy): array
    {
        $plan = $this->plan($occupancy, max(1, (int) Setting::get('max_months')));
        $credit = $this->credit($occupancy);
        $running = 0.0;
        $options = [];

        foreach ($plan as $i => $month) {
            $running += $month['amount'];
            $options[] = [
                'months' => $i + 1,
                'amount' => max(0, round($running - $credit, 2)),
                'through' => $month['label'],
                'label' => $month['label'],
            ];
        }

        return $options;
    }

    /**
     * What paying for $months months costs now, after any credit on account.
     */
    public function amountFor(Occupancy $occupancy, int $months): float
    {
        return max(0, round($this->plan($occupancy, $months)->sum('amount') - $this->credit($occupancy), 2));
    }

    /**
     * Start a Paystack checkout. The payment stays "pending" until Paystack confirms it.
     */
    public function startOnlinePayment(Occupancy $occupancy, int $months): Payment
    {
        $amount = $this->amountFor($occupancy, $months);

        if ($amount <= 0) {
            throw new InvalidArgumentException('Nothing to pay: the credit on this account already covers those months.');
        }

        return Payment::create([
            'occupancy_id' => $occupancy->id,
            'amount' => $amount,
            'months' => $months,
            'method' => 'online',
            'source' => 'online',
            'gateway_reference' => 'PXB-'.$occupancy->id.'-'.Str::upper(Str::random(12)),
            'paid_on' => today(),
            'status' => 'pending',
        ]);
    }

    /**
     * Save a manual payment (cash, transfer, POS, cheque). It waits for an accountant to clear it.
     *
     * @param  array{amount?: float|string|null, months?: int|null, method: string, paid_on: mixed, reference?: string|null}  $data
     */
    public function recordManualPayment(Occupancy $occupancy, array $data, User $by): Payment
    {
        $months = isset($data['months']) ? (int) $data['months'] : null;
        $amount = $data['amount'] ?? null;

        if (! $amount && ! $months) {
            throw new InvalidArgumentException('Give an amount or the number of months paid.');
        }

        return Payment::create([
            'occupancy_id' => $occupancy->id,
            'amount' => $amount ?: $this->amountFor($occupancy, $months),
            'months' => $months,
            'method' => $data['method'],
            'source' => 'manual',
            'reference' => $data['reference'] ?? null,
            'paid_on' => $data['paid_on'],
            'status' => 'under_review',
            'recorded_by' => $by->id,
        ]);
    }

    /**
     * Accountant clears a manual payment. A reference and at least one attachment are required.
     */
    public function clearManualPayment(Payment $payment, User $by, string $reference): void
    {
        if ($payment->source !== 'manual') {
            throw new InvalidArgumentException('Only manual payments are cleared by hand.');
        }

        if (! $payment->attachments()->exists()) {
            throw new InvalidArgumentException('Attach proof of payment before clearing it.');
        }

        $payment->update(['reference' => $reference]);
        $this->confirmPayment($payment, $by);
    }

    /**
     * Mark a payment confirmed, issue any months paid ahead, and apply the money oldest month first.
     * Safe to call twice (e.g. Paystack callback and webhook): the second call does nothing.
     */
    public function confirmPayment(Payment $payment, ?User $by = null): bool
    {
        if ($payment->status === 'rejected') {
            throw new InvalidArgumentException('A rejected payment cannot be confirmed.');
        }

        $confirmed = DB::transaction(function () use ($payment, $by) {
            $locked = Payment::whereKey($payment->id)->lockForUpdate()->first();

            if ($locked->status === 'confirmed') {
                return false;
            }

            $locked->update([
                'status' => 'confirmed',
                'receipt_number' => sprintf('RCT-%06d', $locked->id),
                'reviewed_by' => $by?->id,
                'reviewed_at' => now(),
                'rejection_reason' => null,
            ]);

            if ($locked->months) {
                $this->ensureMonths($locked->occupancy, $locked->months);
            }

            $this->allocate($locked);

            return true;
        });

        $payment->refresh();

        return $confirmed;
    }

    public function rejectPayment(Payment $payment, User $by, string $reason): void
    {
        if ($payment->status === 'confirmed') {
            throw new InvalidArgumentException('A confirmed payment cannot be rejected.');
        }

        $payment->update([
            'status' => 'rejected',
            'reviewed_by' => $by->id,
            'reviewed_at' => now(),
            'rejection_reason' => $reason,
        ]);
    }

    public function cancelInvoice(Invoice $invoice): void
    {
        if ((float) $invoice->amount_paid > 0) {
            throw new InvalidArgumentException('An invoice with payments against it cannot be cancelled.');
        }

        $invoice->update(['status' => 'cancelled']);
    }

    /**
     * Money paid that is not yet applied to an invoice.
     */
    public function credit(Occupancy $occupancy): float
    {
        $paid = (float) $occupancy->payments()->confirmed()->sum('amount');
        $applied = (float) DB::table('payment_allocations')
            ->join('payments', 'payments.id', '=', 'payment_allocations.payment_id')
            ->where('payments.occupancy_id', $occupancy->id)
            ->where('payments.status', 'confirmed')
            ->sum('payment_allocations.amount');

        return round($paid - $applied, 2);
    }

    /**
     * Make sure invoices exist for every month in the plan (months paid ahead).
     */
    protected function ensureMonths(Occupancy $occupancy, int $months): void
    {
        $this->plan($occupancy, $months)
            ->whereNull('invoice_id')
            ->each(fn (array $m) => $this->issueForPeriod($occupancy, Carbon::createFromFormat('Y-m-d', $m['period'].'-01')));
    }

    /**
     * Spread the unapplied part of a confirmed payment over open invoices, oldest month first.
     */
    protected function allocate(Payment $payment): void
    {
        $remaining = round((float) $payment->amount - (float) $payment->allocations()->sum('amount'), 2);

        if ($remaining <= 0) {
            return;
        }

        $invoices = Invoice::open()
            ->where('occupancy_id', $payment->occupancy_id)
            ->orderBy('period')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($invoices as $invoice) {
            $take = min($remaining, $invoice->balance());

            if ($take <= 0) {
                continue;
            }

            $payment->allocations()->create(['invoice_id' => $invoice->id, 'amount' => $take]);

            $paid = round((float) $invoice->amount_paid + $take, 2);
            $full = $paid >= (float) $invoice->amount;

            $invoice->update([
                'amount_paid' => $paid,
                'status' => $full ? 'paid' : 'partial',
                'paid_at' => $full ? $payment->paid_on : null,
            ]);

            $remaining = round($remaining - $take, 2);

            if ($remaining <= 0) {
                break;
            }
        }
    }

    /**
     * Use any overpayment on account against open invoices.
     */
    protected function applyCredit(Occupancy $occupancy): void
    {
        $occupancy->payments()
            ->confirmed()
            ->withSum('allocations', 'amount')
            ->get()
            ->filter(fn (Payment $p) => (float) $p->amount > (float) $p->allocations_sum_amount)
            ->each(fn (Payment $p) => DB::transaction(fn () => $this->allocate($p)));
    }
}
