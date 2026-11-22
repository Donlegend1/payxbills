<?php

namespace App\Services;

use App\Models\Payment;
use App\Tenancy\CurrentCompany;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Paystack checkout: initialize a transaction, verify it, and check webhook signatures.
 * Amounts are sent in kobo. Docs: https://paystack.com/docs/api/transaction/
 *
 * Uses the current company's own secret key (Settings page) so money goes straight to that company,
 * falling back to PAYSTACK_SECRET_KEY in .env (the platform's account) when the company has none.
 */
class PaystackService
{
    protected const BASE = 'https://api.paystack.co';

    public function configured(): bool
    {
        return filled($this->secret());
    }

    /**
     * True when the current company uses its own Paystack account rather than the platform's.
     */
    public function usesCompanyAccount(): bool
    {
        return filled(CurrentCompany::get()?->paystack_secret_key);
    }

    public function secret(): string
    {
        return (string) (CurrentCompany::get()?->paystack_secret_key ?: config('services.paystack.secret_key'));
    }

    /**
     * Start a checkout and return the Paystack page to send the payer to.
     */
    public function initialize(Payment $payment, string $email, string $callbackUrl): string
    {
        $response = $this->request()->post(self::BASE.'/transaction/initialize', [
            'email' => $email,
            'amount' => (int) round((float) $payment->amount * 100),
            'currency' => 'NGN',
            'reference' => $payment->gateway_reference,
            'callback_url' => $callbackUrl,
            'metadata' => [
                'company_id' => $payment->company_id,
                'payment_id' => $payment->id,
                'occupancy_id' => $payment->occupancy_id,
                'months' => $payment->months,
            ],
        ]);

        $url = $response->json('data.authorization_url');

        if ($response->failed() || ! $response->json('status') || ! $url) {
            throw new RuntimeException('Paystack could not start the payment: '.($response->json('message') ?? Str::limit($response->body(), 200)));
        }

        return $url;
    }

    /**
     * True when Paystack reports the transaction paid in full, in naira, for this payment.
     */
    public function verify(Payment $payment): bool
    {
        $response = $this->request()->get(self::BASE.'/transaction/verify/'.rawurlencode($payment->gateway_reference));

        if ($response->failed() || ! $response->json('status')) {
            throw new RuntimeException('Paystack could not verify the payment: '.($response->json('message') ?? 'HTTP '.$response->status()));
        }

        return $response->json('data.status') === 'success'
            && $response->json('data.currency') === 'NGN'
            && (int) $response->json('data.amount') >= (int) round((float) $payment->amount * 100);
    }

    /**
     * Webhooks are signed with HMAC-SHA512 of the raw body using the secret key.
     */
    public function validSignature(string $payload, ?string $signature): bool
    {
        return $this->configured()
            && $signature !== null
            && hash_equals(hash_hmac('sha512', $payload, $this->secret()), $signature);
    }

    protected function request()
    {
        if (! $this->configured()) {
            throw new RuntimeException('Paystack is not configured: set PAYSTACK_SECRET_KEY.');
        }

        return Http::withToken($this->secret())->acceptJson()->timeout(30);
    }
}
