<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Http\Middleware\SetCurrentCompany;
use App\Models\Category;
use App\Models\Company;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\Occupancy;
use App\Models\Payment;
use App\Support\Money;
use App\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The super admin (Fivestar Technologies) oversees every company on PayXbills:
 *  - an overview of all companies, with suspend / reactivate;
 *  - lists of every facility user, payment, invoice, facility and category across companies;
 *  - "Open" a company to manage it with full rights, as themselves;
 *  - "Log in as admin" to see exactly what a company's admin sees.
 */
class PlatformController extends Controller
{
    public function index(): Response
    {
        $this->allCompanies();
        $month = today()->format('Y-m');
        $range = [today()->startOfMonth(), today()->endOfMonth()];

        $collected = Payment::acrossCompanies()->confirmed()->whereBetween('paid_on', $range)
            ->selectRaw('company_id, sum(amount) as total')->groupBy('company_id')->pluck('total', 'company_id');
        $billed = Invoice::acrossCompanies()->where('period', $month)->where('status', '!=', 'cancelled')
            ->selectRaw('company_id, sum(amount) as total')->groupBy('company_id')->pluck('total', 'company_id');
        $outstanding = Invoice::acrossCompanies()->open()->whereDate('due_date', '<=', today())
            ->selectRaw('company_id, sum(amount - amount_paid) as total')->groupBy('company_id')->pluck('total', 'company_id');
        $awaiting = Payment::acrossCompanies()->where('status', 'under_review')
            ->selectRaw('company_id, count(*) as total')->groupBy('company_id')->pluck('total', 'company_id');

        $companies = Company::query()
            ->withCount([
                'facilities',
                'occupancies as facility_users' => fn ($q) => $q->where('status', 'active'),
                'occupancies as billed_users' => fn ($q) => $q->where('status', 'active')->where('billing_active', true),
                'users as staff' => fn ($q) => $q->where('role', '!=', Role::FacilityUser->value),
            ])
            ->orderBy('name')
            ->get()
            ->map(fn (Company $c) => [
                ...$c->only('id', 'name', 'slug', 'email', 'phone', 'is_active'),
                'logo' => $c->logoUrl(),
                'facilities' => $c->facilities_count,
                'facility_users' => $c->facility_users,
                'billed_users' => $c->billed_users,
                'staff' => $c->staff,
                'billed' => (float) ($billed[$c->id] ?? 0),
                'collected' => (float) ($collected[$c->id] ?? 0),
                'outstanding' => round((float) ($outstanding[$c->id] ?? 0), 2),
                'awaiting' => (int) ($awaiting[$c->id] ?? 0),
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
                'billed' => $companies->sum('billed'),
                'collected' => $companies->sum('collected'),
                'outstanding' => $companies->sum('outstanding'),
            ],
        ]);
    }

    public function facilityUsers(Request $request): Response
    {
        $this->allCompanies();
        $f = $this->filters($request);

        $rows = Occupancy::acrossCompanies()
            ->with(['company:id,name', 'user:id,name,email,phone', 'facility:id,name', 'space:id,number,label', 'category:id,name,monthly_amount'])
            ->withSum(['invoices as open_amount' => fn ($q) => $q->open()], 'amount')
            ->withSum(['invoices as open_paid' => fn ($q) => $q->open()], 'amount_paid')
            ->when($f['company'], fn (Builder $q, $id) => $q->where('company_id', $id))
            ->when($f['status'], fn (Builder $q, $s) => match ($s) {
                'billing' => $q->where('status', 'active')->where('billing_active', true),
                'not-activated' => $q->where('status', 'active')->where('billing_active', false)->whereNull('activated_at'),
                'deactivated' => $q->where('status', 'active')->where('billing_active', false)->whereNotNull('activated_at'),
                'removed' => $q->where('status', 'ended'),
                default => $q,
            })
            ->when($f['q'], fn (Builder $q, $s) => $q->where(fn (Builder $w) => $w
                ->where('code', 'like', "%{$s}%")->orWhere('street', 'like', "%{$s}%")->orWhere('district', 'like', "%{$s}%")
                ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"))
                ->orWhereHas('category', fn (Builder $c) => $c->where('name', 'like', "%{$s}%"))
                ->orWhereHas('facility', fn (Builder $c) => $c->where('name', 'like', "%{$s}%"))))
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Occupancy $o) => [
                'company_id' => $o->company_id,
                'company' => $o->company?->name,
                'cells' => [
                    $o->code,
                    [$o->user->name, implode(' · ', array_filter([$o->user->email, $o->user->phone]))],
                    $o->placeName(),
                    [$o->categoryName() ?? '—', Money::format($o->monthlyAmount()).'/month'],
                    ['pill', $o->isActive() ? ['active' => 'Billing on', 'pending' => 'Not activated', 'deactivated' => 'Deactivated'][$o->billingState()] : 'Removed'],
                    Money::format(round((float) $o->open_amount - (float) $o->open_paid, 2)),
                ],
            ]);

        return $this->records('Facility users', 'Every facility user of every company.', ['Account', 'Facility user', 'Place', 'Category', 'Status', 'Outstanding'], $rows, $f,
            ['billing' => 'Being billed', 'not-activated' => 'Not activated', 'deactivated' => 'Deactivated', 'removed' => 'Removed']);
    }

    public function payments(Request $request): Response
    {
        $this->allCompanies();
        $f = $this->filters($request);

        $rows = Payment::acrossCompanies()
            ->with(['company:id,name', 'occupancy:id,code,user_id', 'occupancy.user:id,name'])
            ->where(fn (Builder $q) => $q->where('status', '!=', 'pending')->orWhere('created_at', '>=', now()->subDay()))
            ->when($f['company'], fn (Builder $q, $id) => $q->where('company_id', $id))
            ->when($f['status'], fn (Builder $q, $s) => $q->where('status', $s))
            ->when($f['q'], fn (Builder $q, $s) => $q->where(fn (Builder $w) => $w
                ->where('receipt_number', 'like', "%{$s}%")->orWhere('reference', 'like', "%{$s}%")->orWhere('gateway_reference', 'like', "%{$s}%")
                ->orWhereHas('occupancy', fn (Builder $o) => $o->where('code', 'like', "%{$s}%")->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$s}%")))))
            ->latest('paid_on')->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Payment $p) => [
                'company_id' => $p->company_id,
                'company' => $p->company?->name,
                'cells' => [
                    $p->paid_on->format('j M Y'),
                    [$p->occupancy->user->name, $p->occupancy->code],
                    Money::format($p->amount),
                    Payment::METHODS[$p->method],
                    $p->receipt_number ?? $p->reference ?? '—',
                    ['pill', Payment::STATUSES[$p->status]],
                ],
            ]);

        $summary = Payment::acrossCompanies()->confirmed()->whereBetween('paid_on', [today()->startOfMonth(), today()->endOfMonth()])
            ->when($f['company'], fn (Builder $q, $id) => $q->where('company_id', $id))->sum('amount');

        return $this->records('Payments', 'Collected this month: '.Money::format($summary).($f['company'] ? '' : ' across all companies').'.', ['Date', 'Facility user', 'Amount', 'Method', 'Receipt / reference', 'Status'], $rows, $f, Payment::STATUSES);
    }

    public function invoices(Request $request): Response
    {
        $this->allCompanies();
        $f = $this->filters($request);

        $rows = Invoice::acrossCompanies()
            ->with(['company:id,name', 'occupancy:id,code,user_id', 'occupancy.user:id,name'])
            ->when($f['company'], fn (Builder $q, $id) => $q->where('company_id', $id))
            ->when($f['status'], fn (Builder $q, $s) => $s === 'overdue' ? $q->open()->whereDate('due_date', '<', today()) : $q->where('status', $s))
            ->when($f['q'], fn (Builder $q, $s) => $q->where(fn (Builder $w) => $w->where('number', 'like', "%{$s}%")
                ->orWhereHas('occupancy', fn (Builder $o) => $o->where('code', 'like', "%{$s}%")->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$s}%")))))
            ->latest('period')->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Invoice $i) => [
                'company_id' => $i->company_id,
                'company' => $i->company?->name,
                'cells' => [
                    $i->number,
                    [$i->occupancy->user->name, $i->occupancy->code],
                    $i->periodLabel(),
                    Money::format($i->amount),
                    Money::format($i->balance()),
                    ['pill', in_array($i->status, Invoice::OPEN, true) && $i->due_date->lt(today()) ? 'Overdue' : ucfirst($i->status)],
                ],
            ]);

        return $this->records('Invoices', 'Every monthly bill issued by every company.', ['Invoice', 'Facility user', 'Month', 'Amount', 'Balance', 'Status'], $rows, $f,
            ['unpaid' => 'Unpaid', 'partial' => 'Partial', 'overdue' => 'Overdue', 'paid' => 'Paid', 'cancelled' => 'Cancelled']);
    }

    public function facilities(Request $request): Response
    {
        $this->allCompanies();
        $f = $this->filters($request);

        $rows = Facility::acrossCompanies()
            ->with(['company:id,name', 'supervisor:id,name'])
            ->withCount(['spaces', 'occupancies as occupied' => fn ($q) => $q->where('status', 'active')])
            ->when($f['company'], fn (Builder $q, $id) => $q->where('company_id', $id))
            ->when($f['status'], fn (Builder $q, $s) => $q->where('is_active', $s === 'active'))
            ->when($f['q'], fn (Builder $q, $s) => $q->where(fn (Builder $w) => $w->where('name', 'like', "%{$s}%")->orWhere('address', 'like', "%{$s}%")))
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Facility $x) => [
                'company_id' => $x->company_id,
                'company' => $x->company?->name,
                'cells' => [
                    [$x->name, $x->address],
                    $x->supervisor?->name ?? '—',
                    $x->spaces_count,
                    $x->occupied,
                    ['pill', $x->is_active ? 'Active' : 'Inactive'],
                ],
            ]);

        return $this->records('Facilities', 'Every facility of every company.', ['Facility', 'Supervisor', 'Spaces', 'Facility users', 'Status'], $rows, $f, ['active' => 'Active', 'inactive' => 'Inactive']);
    }

    public function categories(Request $request): Response
    {
        $this->allCompanies();
        $f = $this->filters($request);

        $rows = Category::acrossCompanies()
            ->with('company:id,name')
            ->withCount(['occupancies as members' => fn ($q) => $q->where('status', 'active')])
            ->when($f['company'], fn (Builder $q, $id) => $q->where('company_id', $id))
            ->when($f['status'], fn (Builder $q, $s) => $q->where('is_active', $s === 'active'))
            ->when($f['q'], fn (Builder $q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->orderBy('company_id')->orderBy('name')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (Category $c) => [
                'company_id' => $c->company_id,
                'company' => $c->company?->name,
                'cells' => [
                    [$c->name, $c->description],
                    Money::format($c->monthly_amount),
                    $c->members,
                    Money::format((float) $c->monthly_amount * $c->members),
                    ['pill', $c->is_active ? 'Active' : 'Inactive'],
                ],
            ]);

        return $this->records('Categories', 'Every company\'s categories and what their facility users pay.', ['Category', 'Monthly amount', 'Facility users', 'Monthly value', 'Status'], $rows, $f, ['active' => 'Active', 'inactive' => 'Inactive']);
    }

    /**
     * Manage a company with full rights, as the super admin. "Back to all companies" closes it.
     */
    public function open(Request $request, Company $company): RedirectResponse
    {
        $request->session()->put(SetCurrentCompany::SESSION_KEY, $company->id);

        return redirect()->route('dashboard')->with('success', "You are managing {$company->name} as super admin.");
    }

    public function close(Request $request): RedirectResponse
    {
        $request->session()->forget(SetCurrentCompany::SESSION_KEY);

        return redirect()->route('platform.companies');
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
        $request->session()->forget(SetCurrentCompany::SESSION_KEY);
        $request->session()->put(['impersonator_id' => $platformAdmin->id, 'impersonator_name' => $platformAdmin->name]);

        return redirect()->route('dashboard')->with('success', "You are now logged in as {$admin->name} of {$company->name}.");
    }

    /**
     * Platform pages always look across every company, even while one is open.
     */
    protected function allCompanies(): void
    {
        CurrentCompany::set(null);
    }

    /**
     * @return array{company: int|null, status: string|null, q: string|null}
     */
    protected function filters(Request $request): array
    {
        return [
            'company' => $request->integer('company') ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'q' => trim($request->string('q')->toString()) ?: null,
        ];
    }

    /**
     * The shared list page: a table with a company column, filters and paging.
     */
    protected function records(string $title, string $sub, array $columns, LengthAwarePaginator $rows, array $filters, array $statuses): Response
    {
        return Inertia::render('Platform/Records', [
            'title' => $title,
            'sub' => $sub,
            'columns' => $columns,
            'rows' => $rows,
            'filters' => $filters,
            'statuses' => $statuses,
            'companies' => Company::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
