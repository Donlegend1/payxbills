<?php

namespace App\Http\Controllers;

use App\Models\Occupancy;
use App\Models\Payment;
use App\Services\AccountSummary;
use App\Services\BillingService;
use App\Services\OnlinePayment;
use App\Services\PaymentProof;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The facility user's own area: their space, what they owe, paying online or by transfer, and receipts.
 */
class PortalController extends Controller
{
    public function index(Request $request, AccountSummary $summary): Response
    {
        $occupancy = $this->occupancy($request);

        return Inertia::render('Portal/Home', [
            'account' => $occupancy ? $summary->account($occupancy) : null,
            'options' => $occupancy ? $summary->payOptions($occupancy) : [],
            'invoices' => $occupancy ? $summary->invoices($occupancy) : [],
            'payments' => $occupancy ? $summary->payments($occupancy) : [],
            'bankDetails' => $summary->bankDetails(),
            'methods' => array_intersect_key(Payment::METHODS, array_flip(['bank_transfer', 'pos', 'cheque'])),
        ]);
    }

    public function pay(Request $request, OnlinePayment $online): HttpResponse
    {
        $data = $request->validate(['months' => ['required', 'integer', 'min:1']]);
        $occupancy = $this->occupancy($request) ?? abort(404);

        try {
            return Inertia::location($online->begin($occupancy, (int) $data['months']));
        } catch (InvalidArgumentException|RuntimeException $e) {
            report($e);

            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * "I paid by transfer": the facility user uploads proof; an accountant clears it.
     */
    public function submitPayment(Request $request, BillingService $billing, PaymentProof $proof): RedirectResponse
    {
        $occupancy = $this->occupancy($request) ?? abort(404);

        $data = $request->validate([
            'months' => ['required', 'integer', 'min:1', 'max:120'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'method' => ['required', Rule::in(['bank_transfer', 'pos', 'cheque'])],
            'reference' => ['required', 'string', 'max:100'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'files' => ['required', 'array', 'min:1', 'max:5'],
            'files.*' => PaymentProof::RULES,
        ]);

        $payment = $billing->recordManualPayment($occupancy, $data, $request->user());
        $proof->store($payment, $data['files'], $request->user());

        return back()->with('success', 'Thank you. Your payment will show as confirmed once the accountant has checked it.');
    }

    protected function occupancy(Request $request): ?Occupancy
    {
        return $request->user()->occupancy;
    }
}
