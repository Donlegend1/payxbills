<?php

namespace App\Http\Controllers;

use App\Models\Occupancy;
use App\Models\Setting;
use App\Services\AccountSummary;
use App\Services\OnlinePayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The pay page linked from "payment due" emails and SMS. Signed URL, no login needed.
 */
class PayController extends Controller
{
    public function show(Occupancy $occupancy, AccountSummary $summary): Response
    {
        $this->actAsCompanyOf($occupancy);

        return Inertia::render('Public/Pay', [
            'account' => $summary->account($occupancy),
            'options' => $summary->payOptions($occupancy),
            'invoices' => array_slice($summary->invoices($occupancy), 0, 6),
            'bankDetails' => $summary->bankDetails(),
            'company' => Setting::get('company_name'),
            'logo' => $occupancy->company->logoUrl(),
            'action' => URL::signedRoute('pay.start', $occupancy),
        ]);
    }

    public function start(Request $request, Occupancy $occupancy, OnlinePayment $online): HttpResponse
    {
        $this->actAsCompanyOf($occupancy);
        $data = $request->validate(['months' => ['required', 'integer', 'min:1']]);

        try {
            return Inertia::location($online->begin($occupancy, (int) $data['months']));
        } catch (InvalidArgumentException|RuntimeException $e) {
            report($e);

            return back()->with('error', $e->getMessage());
        }
    }

    /**
     * Paystack sends the payer back here with ?reference=... after checkout.
     */
    public function callback(Request $request, OnlinePayment $online): RedirectResponse
    {
        $payment = $online->complete((string) $request->query('reference', $request->query('trxref', '')));

        if (! $payment) {
            return redirect()->route('home')->with('error', 'We could not find that payment.');
        }

        if ($payment->status === 'confirmed') {
            return $request->user()?->can('use-portal')
                ? redirect()->route('portal')->with('success', "Payment received. Receipt {$payment->receipt_number} has been sent to you.")
                : redirect()->to($payment->receiptUrl());
        }

        $back = $request->user()?->can('use-portal') ? route('portal') : $payment->occupancy->payUrl();

        return redirect()->to($back)->with('error', 'Your payment has not been confirmed yet. If you were charged, it will show here shortly.');
    }
}
