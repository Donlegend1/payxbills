<?php

namespace App\Http\Controllers;

use App\Models\Payment;
use App\Services\OnlinePayment;
use App\Services\PaystackService;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Paystack tells us about successful charges here, even if the payer never returns to the site.
 * Every company sets https://<your-domain>/webhooks/paystack as the webhook URL on its Paystack dashboard.
 *
 * Each company may use its own Paystack account, so the payment is looked up first and the
 * signature checked with that company's secret key.
 */
class PaystackWebhookController extends Controller
{
    public function __invoke(Request $request, PaystackService $paystack, OnlinePayment $online): Response
    {
        $reference = (string) $request->input('data.reference', '');
        $payment = $reference !== '' ? Payment::acrossCompanies()->with('company')->where('gateway_reference', $reference)->first() : null;

        $handle = function () use ($request, $paystack, $online, $reference, $payment) {
            if (! $paystack->validSignature($request->getContent(), $request->header('x-paystack-signature'))) {
                return response('Invalid signature', 401);
            }

            if ($payment && $request->input('event') === 'charge.success') {
                // Verifies with Paystack again before confirming, so a replayed body cannot fake a payment
                $online->complete($reference);
            }

            return response()->noContent(200);
        };

        return $payment?->company ? CurrentCompany::run($payment->company, $handle) : $handle();
    }
}
