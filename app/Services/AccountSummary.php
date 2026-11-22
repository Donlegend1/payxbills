<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Occupancy;
use App\Models\Payment;
use App\Models\Setting;

/**
 * What a facility user sees about their account: place, dues, pay options and history.
 * Used by the portal and the signed pay link.
 */
class AccountSummary
{
    public function __construct(protected BillingService $billing) {}

    public function account(Occupancy $occupancy): array
    {
        $occupancy->loadMissing(['user', 'facility', 'space', 'category']);
        $paidThrough = $occupancy->paidThrough();

        return [
            'code' => $occupancy->code,
            'name' => $occupancy->user->name,
            'email' => $occupancy->user->email,
            'facility' => $occupancy->facility->only('name', 'address', 'phone', 'email'),
            'space' => $occupancy->space->name(),
            'category' => $occupancy->category->name,
            'monthly' => (float) $occupancy->category->monthly_amount,
            'paid_through' => $paidThrough?->format('F Y'),
            'outstanding' => $occupancy->outstanding(),
            'overdue' => $occupancy->invoices()->overdue()->count(),
            'credit' => $this->billing->credit($occupancy),
            'active' => $occupancy->isActive(),
        ];
    }

    /**
     * @return list<array{months: int, amount: float, through: string, label: string}>
     */
    public function payOptions(Occupancy $occupancy): array
    {
        return $occupancy->isActive() ? $this->billing->payOptions($occupancy) : [];
    }

    /**
     * Months billed so far, newest first: unpaid ones are the "pending" transactions.
     */
    public function invoices(Occupancy $occupancy, int $limit = 24): array
    {
        return $occupancy->invoices()
            ->where('status', '!=', 'cancelled')
            ->orderByDesc('period')
            ->limit($limit)
            ->get()
            ->map(fn (Invoice $i) => [
                'id' => $i->id,
                'number' => $i->number,
                'month' => $i->periodLabel(),
                'amount' => (float) $i->amount,
                'paid' => (float) $i->amount_paid,
                'balance' => $i->balance(),
                'due' => $i->due_date->format('j M Y'),
                'status' => $i->displayStatus(),
            ])
            ->all();
    }

    /**
     * Payments of every status. Abandoned online checkouts older than a day are left out.
     */
    public function payments(Occupancy $occupancy): array
    {
        return $occupancy->payments()
            ->with(['allocations.invoice:id,period', 'attachments:id,payment_id,original_name'])
            ->where(fn ($q) => $q->where('status', '!=', 'pending')->orWhere('created_at', '>=', now()->subDay()))
            ->latest('paid_on')
            ->latest('id')
            ->get()
            ->map(fn (Payment $p) => [
                'id' => $p->id,
                'date' => $p->paid_on->format('j M Y'),
                'amount' => (float) $p->amount,
                'months' => $p->months,
                'covers' => $p->allocations->map(fn ($a) => $a->invoice->periodLabel())->unique()->values()->all(),
                'method' => Payment::METHODS[$p->method],
                'reference' => $p->reference,
                'status' => $p->status === 'pending' ? 'Awaiting payment' : Payment::STATUSES[$p->status],
                'status_key' => $p->status,
                'receipt' => $p->receipt_number,
                'rejection_reason' => $p->rejection_reason,
                'attachments' => $p->attachments->map(fn ($a) => ['id' => $a->id, 'name' => $a->original_name]),
            ])
            ->all();
    }

    public function bankDetails(): string
    {
        return (string) Setting::get('bank_details');
    }
}
