<?php

namespace App\Services;

use App\Models\Occupancy;
use App\Models\Payment;
use App\Models\Setting;
use App\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Paying N months online through Paystack: start the checkout, then confirm the payment when
 * Paystack sends the payer back (callback) or tells us directly (webhook) – whichever comes first.
 */
class OnlinePayment
{
    public function __construct(
        protected BillingService $billing,
        protected PaystackService $paystack,
        protected Messenger $messenger,
    ) {}

    /**
     * Create the pending payment and return the Paystack checkout URL.
     */
    public function begin(Occupancy $occupancy, int $months): string
    {
        if (! $occupancy->isActive()) {
            throw new InvalidArgumentException('This account is no longer active.');
        }

        $max = (int) Setting::get('max_months');
        if ($months < 1 || $months > $max) {
            throw new InvalidArgumentException("Choose between 1 and {$max} months.");
        }

        if (! $this->paystack->configured()) {
            throw new InvalidArgumentException('Online payment is not available yet. Please pay by bank transfer and upload your proof of payment.');
        }

        $payment = $this->billing->startOnlinePayment($occupancy, $months);

        return $this->paystack->initialize($payment, $occupancy->user->email, route('pay.callback'));
    }

    /**
     * Verify with Paystack and confirm. Returns the payment, or null if the reference is unknown.
     * Sends the receipt only the first time the payment is confirmed.
     */
    public function complete(string $reference): ?Payment
    {
        // The payer may come back logged out, so look across companies and then work as the payment's company
        $payment = $reference !== '' ? Payment::acrossCompanies()->with('company')->where('gateway_reference', $reference)->first() : null;

        if (! $payment || $payment->status === 'confirmed') {
            return $payment;
        }

        return CurrentCompany::run($payment->company, fn () => $this->confirm($payment, $reference));
    }

    protected function confirm(Payment $payment, string $reference): Payment
    {
        try {
            if (! $this->paystack->verify($payment)) {
                return $payment;
            }
        } catch (Throwable $e) {
            Log::warning('Paystack verification failed', ['reference' => $reference, 'error' => $e->getMessage()]);

            return $payment;
        }

        $payment->update(['paid_on' => today()]);

        if ($this->billing->confirmPayment($payment)) {
            $this->messenger->receipt($payment);
        }

        return $payment->refresh();
    }
}
