<?php

namespace App\Http\Controllers;

use App\Models\Setting;
use App\Services\PaystackService;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class SettingsController extends Controller
{
    public function edit(PaystackService $paystack): Response
    {
        $secret = $paystack->secret();

        return Inertia::render('App/Settings', [
            'settings' => Setting::values(),
            'logo' => CurrentCompany::get()->logoUrl(),
            'paystack' => [
                'configured' => $secret !== '',
                'test' => Str::startsWith($secret, 'sk_test_'),
                // Own account: payments go straight to the company. Otherwise the platform's account is used.
                'own' => $paystack->usesCompanyAccount(),
                'webhook' => route('webhooks.paystack'),
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'invoice_prefix' => ['required', 'alpha_num', 'max:10'],
            'billing_day' => ['required', 'integer', 'between:1,28'],
            'due_days' => ['required', 'integer', 'between:0,120'],
            'reminder_days' => ['nullable', 'string', 'max:100', 'regex:/^\s*\d+(\s*,\s*\d+)*\s*$/'],
            'grace_days' => ['required', 'integer', 'between:0,60'],
            'max_months' => ['required', 'integer', 'between:1,36'],
            'sms_sender' => ['required', 'string', 'max:11'],
            'bank_details' => ['nullable', 'string', 'max:500'],
            'nrs_enabled' => ['boolean'],
            'auto_invoicing' => ['boolean'],
            // Blank keeps the saved key; "remove" goes back to the platform account
            'paystack_secret_key' => ['nullable', 'string', 'max:200', 'regex:/^(sk_(test|live)_\w+|remove)$/'],
        ], ['reminder_days.regex' => 'Use whole numbers separated by commas, for example 0, 3, 7, 14.', 'paystack_secret_key.regex' => 'Paste the secret key from your Paystack dashboard. It starts with sk_live_ or sk_test_.']);

        if (filled($key = $data['paystack_secret_key'] ?? null)) {
            CurrentCompany::get()->update(['paystack_secret_key' => $key === 'remove' ? null : $key]);
        }

        Setting::put($data);

        return back()->with('success', 'Settings saved.');
    }
}
