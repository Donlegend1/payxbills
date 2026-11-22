<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A company signs up for PayXbills: creates the company and its first admin, then lands on its
 * own (empty) dashboard to add facilities, categories, staff and facility users.
 */
class CompanyRegistrationController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'max:255'],
            'company_phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
            'company_address' => ['nullable', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            // Emails are unique across all companies: one login form serves everyone
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ], ['email.unique' => 'This email already has a PayXbills account. Log in instead, or use another email.']);

        $admin = DB::transaction(function () use ($data) {
            $company = Company::create([
                'name' => $data['company_name'],
                'email' => $data['email'],
                'phone' => $data['company_phone'] ?? null,
                'address' => $data['company_address'] ?? null,
            ]);

            return CurrentCompany::run($company, function () use ($data, $company) {
                Setting::put(['sms_sender' => self::smsSender($company->name)]);

                return User::create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'phone' => $data['company_phone'] ?? null,
                    'password' => $data['password'],
                    'role' => Role::Admin,
                    'is_active' => true,
                    'email_verified_at' => now(),
                ]);
            });
        });

        Auth::login($admin);
        $request->session()->regenerate();

        return redirect()->route('facilities')->with('success', "Welcome to PayXbills, {$admin->company->name}! Start by adding your first facility and its spaces.");
    }

    /**
     * SMS sender IDs are at most 11 letters or digits: "Cosmopolitan Cleaners" → "COSMOPOLITA".
     */
    protected static function smsSender(string $name): string
    {
        return Str::limit(Str::upper(preg_replace('/[^A-Za-z0-9]/', '', $name)), 11, '') ?: 'PAYXBILLS';
    }
}
