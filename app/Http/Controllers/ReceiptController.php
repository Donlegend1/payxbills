<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Printable receipt with the facility details, for confirmed payments.
 */
class ReceiptController extends Controller
{
    /**
     * Opened from the link in the receipt email/SMS (signed, no login).
     */
    public function public(Payment $payment): Response
    {
        $this->actAsCompanyOf($payment);

        return $this->render($payment, false);
    }

    /**
     * From the portal (own payments) or the staff payments list.
     */
    public function show(Request $request, Payment $payment): Response
    {
        $user = $request->user();
        $allowed = $user->can('use-portal') || $user->can('view-payments');

        abort_unless($allowed && Payment::visibleTo($user)->whereKey($payment->id)->exists(), 403);

        return $this->render($payment, true);
    }

    protected function render(Payment $payment, bool $inApp): Response
    {
        abort_unless($payment->status === 'confirmed', 404);

        $payment->load(['occupancy.user', 'occupancy.facility', 'occupancy.space', 'occupancy.category', 'allocations.invoice']);
        $occupancy = $payment->occupancy;

        return Inertia::render('Receipt', [
            'inApp' => $inApp,
            'receipt' => [
                'number' => $payment->receipt_number,
                'date' => $payment->paid_on->format('j M Y'),
                'confirmed_at' => $payment->reviewed_at?->format('j M Y H:i'),
                'amount' => (float) $payment->amount,
                'method' => Payment::METHODS[$payment->method],
                'reference' => $payment->reference ?? $payment->gateway_reference,
                'lines' => $payment->allocations->map(fn ($a) => ['month' => $a->invoice->periodLabel(), 'invoice' => $a->invoice->number, 'amount' => (float) $a->amount])->values(),
                'credit' => round((float) $payment->amount - (float) $payment->allocations->sum('amount'), 2),
                'payer' => [
                    'name' => $occupancy->user->name,
                    'email' => $occupancy->user->email,
                    'phone' => $occupancy->user->phone,
                    'account' => $occupancy->code,
                ],
                'facility' => $occupancy->facilityDetails(),
                'space' => $occupancy->placeName(),
                'category' => $occupancy->categoryName(),
                'paid_through' => $occupancy->paidThrough()?->format('F Y'),
                // Business name and logo of the company that was paid
                'company' => $payment->company->brand(),
            ],
        ]);
    }
}
