<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Payment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The platform area for Fivestar Technologies staff: every company on PayXbills, suspending and
 * reactivating them, and logging in as a company's admin to help them.
 */
class PlatformController extends Controller
{
    public function index(): Response
    {
        $month = [today()->startOfMonth(), today()->endOfMonth()];

        $collected = Payment::acrossCompanies()->confirmed()->whereBetween('paid_on', $month)
            ->selectRaw('company_id, sum(amount) as total')->groupBy('company_id')->pluck('total', 'company_id');

        $companies = Company::query()
            ->withCount([
                'facilities',
                'occupancies as facility_users' => fn ($q) => $q->where('status', 'active'),
                'users as staff' => fn ($q) => $q->where('role', '!=', Role::FacilityUser->value),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Company $c) => [
                ...$c->only('id', 'name', 'slug', 'email', 'phone', 'is_active'),
                'facilities' => $c->facilities_count,
                'facility_users' => $c->facility_users,
                'staff' => $c->staff,
                'collected' => (float) ($collected[$c->id] ?? 0),
                'joined' => $c->created_at->format('j M Y'),
                'own_paystack' => filled($c->paystack_secret_key),
            ]);

        return Inertia::render('Platform/Companies', [
            'companies' => $companies,
            'monthLabel' => today()->format('F Y'),
            'totals' => [
                'companies' => $companies->count(),
                'active' => $companies->where('is_active', true)->count(),
                'facility_users' => $companies->sum('facility_users'),
                'collected' => $companies->sum('collected'),
            ],
        ]);
    }

    /**
     * Suspend or reactivate a company. Its users cannot log in and its pay links stop working while suspended.
     */
    public function update(Request $request, Company $company): RedirectResponse
    {
        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $company->update($data);

        return back()->with('success', $company->is_active ? "{$company->name} reactivated." : "{$company->name} suspended. Its users can no longer log in.");
    }

    /**
     * Log in as the company's admin to see what they see. "Return to my account" comes back here.
     */
    public function impersonate(Request $request, Company $company): RedirectResponse
    {
        $admin = $company->primaryAdmin();

        if (! $admin) {
            return back()->with('error', "{$company->name} has no active admin to log in as.");
        }

        $platformAdmin = $request->user();

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();
        $request->session()->put(['impersonator_id' => $platformAdmin->id, 'impersonator_name' => $platformAdmin->name]);

        return redirect()->route('dashboard')->with('success', "You are now logged in as {$admin->name} of {$company->name}.");
    }
}
