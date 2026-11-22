<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Facility;
use App\Models\Occupancy;
use App\Models\RemovalRequest;
use App\Models\Space;
use App\Models\User;
use App\Services\FacilityUserService;
use App\Services\Messenger;
use App\Support\Money;
use App\Tenancy\CompanyRules;
use App\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Facility users: registered by supervisors (or the super admin) into a vacant space,
 * removed only after the super admin approves.
 */
class FacilityUserController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $request->only('status', 'facility', 'category', 'q');
        $overdue = fn (Builder $q) => $q->overdue();
        $status = $filters['status'] ?? '';

        $occupancies = Occupancy::query()
            ->visibleTo($user)
            ->with(['user:id,name,email,phone,is_active', 'facility:id,name', 'space:id,number,label', 'category:id,name,monthly_amount', 'pendingRemoval.requester:id,name'])
            ->withCount(['invoices as overdue_count' => $overdue])
            ->withSum(['invoices as open_amount' => fn ($q) => $q->open()], 'amount')
            ->withSum(['invoices as open_paid' => fn ($q) => $q->open()], 'amount_paid')
            ->when($status === 'removed', fn (Builder $q) => $q->where('status', 'ended'), fn (Builder $q) => $q->active())
            ->when($status === 'green', fn (Builder $q) => $q->whereDoesntHave('invoices', $overdue))
            ->when($status === 'amber', fn (Builder $q) => $q->whereHas('invoices', $overdue, '=', 1))
            ->when($status === 'red', fn (Builder $q) => $q->whereHas('invoices', $overdue, '>', 1))
            ->when($status === 'removal', fn (Builder $q) => $q->whereHas('pendingRemoval'))
            ->when($filters['facility'] ?? null, fn (Builder $q, $id) => $q->where('facility_id', $id))
            ->when($filters['category'] ?? null, fn (Builder $q, $id) => $q->where('category_id', $id))
            ->when($filters['q'] ?? null, fn (Builder $q, string $s) => $q->where(fn (Builder $w) => $w
                ->where('code', 'like', "%{$s}%")
                ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', "%{$s}%")->orWhere('email', 'like', "%{$s}%")->orWhere('phone', 'like', "%{$s}%"))
                ->orWhereHas('space', fn (Builder $sp) => $sp->where('number', 'like', "%{$s}%"))))
            ->orderBy(Facility::select('name')->whereColumn('facilities.id', 'occupancies.facility_id'))
            ->orderBy(Space::select('number')->whereColumn('spaces.id', 'occupancies.space_id'))
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Occupancy $o) => [
                'id' => $o->id,
                'code' => $o->code,
                'user_id' => $o->user_id,
                'name' => $o->user->name,
                'email' => $o->user->email,
                'phone' => $o->user->phone,
                'facility_id' => $o->facility_id,
                'facility' => $o->facility->name,
                'space_id' => $o->space_id,
                'space' => $o->space->name(),
                'category_id' => $o->category_id,
                'category' => $o->category->name,
                'monthly' => (float) $o->category->monthly_amount,
                'start_date' => $o->start_date->toDateString(),
                'ended' => $o->end_date?->format('j M Y'),
                'is_active' => $o->isActive(),
                'paid_through' => $o->paidThrough()?->format('M Y'),
                'outstanding' => round((float) $o->open_amount - (float) $o->open_paid, 2),
                'overdue_count' => $o->overdue_count,
                'removal' => $o->pendingRemoval ? [
                    'id' => $o->pendingRemoval->id,
                    'reason' => $o->pendingRemoval->reason,
                    'by' => $o->pendingRemoval->requester?->name,
                    'at' => $o->pendingRemoval->created_at->format('j M Y'),
                ] : null,
            ]);

        $facilities = Facility::visibleTo($user)->active()->orderBy('name')
            ->with(['spaces' => fn ($q) => $q->vacant()->orderByRaw('length(number), number')])
            ->get()
            ->map(fn (Facility $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'vacant' => $f->spaces->map(fn (Space $s) => ['id' => $s->id, 'name' => $s->name()]),
            ]);

        return Inertia::render('App/FacilityUsers', [
            'occupancies' => $occupancies,
            'filters' => $filters,
            'facilities' => $facilities,
            'categories' => Category::active()->orderBy('name')->get(['id', 'name', 'monthly_amount'])
                ->map(fn (Category $c) => ['id' => $c->id, 'name' => $c->name.' – '.Money::format($c->monthly_amount)]),
            'pendingRemovals' => RemovalRequest::where('status', 'pending')
                ->whereHas('occupancy', fn (Builder $q) => $q->visibleTo($user))
                ->count(),
        ]);
    }

    public function store(Request $request, FacilityUserService $service): RedirectResponse
    {
        $data = $request->validate([
            ...$this->personRules(),
            // A removed facility user's email may be used again: they are re-registered on the same account
            'email' => ['required', 'email', 'max:255', function (string $attribute, mixed $value, Closure $fail) {
                // Emails are unique across all companies, so look beyond this one
                $existing = User::acrossCompanies()->where('email', $value)->first();

                if ($existing && $existing->company_id !== CurrentCompany::id()) {
                    $fail('This email is already registered with another organisation on PayXbills. Use a different email.');
                } elseif ($existing && ! FacilityUserService::canRejoin($existing)) {
                    $fail($existing->isFacilityUser()
                        ? "{$existing->name} already holds a space. Remove them first, or edit them to move them to another space."
                        : 'This email belongs to a staff account.');
                }
            }],
            'space_id' => ['required', CompanyRules::exists('spaces')],
            'category_id' => ['required', CompanyRules::exists('categories')->where('is_active', true)],
            'start_date' => ['required', 'date'],
        ]);

        $returning = User::acrossCompanies()->where('email', $data['email'])->exists();

        try {
            $occupancy = $service->register($data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['space_id' => $e->getMessage()]);
        }

        $done = $returning ? 're-registered (returning facility user, earlier history kept)' : 'registered';

        return back()->with('success', "{$occupancy->user->name} {$done} in {$occupancy->space->name()}. Login details were emailed to {$occupancy->user->email}.");
    }

    public function update(Request $request, Occupancy $occupancy, FacilityUserService $service): RedirectResponse
    {
        $this->authorizeVisible($request, $occupancy);

        if (! $occupancy->isActive()) {
            return back()->with('error', 'This facility user has been removed.');
        }

        $data = $request->validate([
            ...$this->personRules(),
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($occupancy->user_id)],
            'space_id' => ['required', CompanyRules::exists('spaces')],
            'category_id' => ['required', CompanyRules::exists('categories')],
        ]);

        try {
            $service->update($occupancy, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['space_id' => $e->getMessage()]);
        }

        return back()->with('success', "{$data['name']} updated.");
    }

    public function resendLogin(Request $request, Occupancy $occupancy, FacilityUserService $service): RedirectResponse
    {
        $this->authorizeVisible($request, $occupancy);

        if (! $occupancy->isActive()) {
            return back()->with('error', 'This facility user has been removed.');
        }

        $service->resendLogin($occupancy->user);

        return back()->with('success', "New login details emailed to {$occupancy->user->email}.");
    }

    public function remind(Request $request, Occupancy $occupancy, Messenger $messenger): RedirectResponse
    {
        $this->authorizeVisible($request, $occupancy);

        $logs = $messenger->occupancyReminder($occupancy);

        return $logs->isEmpty()
            ? back()->with('error', "{$occupancy->user->name} has nothing outstanding.")
            : back()->with('success', "Reminder sent to {$occupancy->user->name}.");
    }

    public function requestRemoval(Request $request, Occupancy $occupancy, FacilityUserService $service): RedirectResponse
    {
        $this->authorizeVisible($request, $occupancy);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        try {
            $removal = $service->requestRemoval($occupancy, $request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', $removal->status === 'approved'
            ? "{$occupancy->user->name} removed. {$occupancy->space->name()} is now vacant."
            : 'Removal requested. Your company admin must approve it.');
    }

    public function approveRemoval(Request $request, RemovalRequest $removal, FacilityUserService $service): RedirectResponse
    {
        return $this->review($request, $removal, fn ($note) => $service->approveRemoval($removal, $request->user(), $note), 'removed');
    }

    public function rejectRemoval(Request $request, RemovalRequest $removal, FacilityUserService $service): RedirectResponse
    {
        return $this->review($request, $removal, fn ($note) => $service->rejectRemoval($removal, $request->user(), $note), 'kept');
    }

    protected function review(Request $request, RemovalRequest $removal, callable $action, string $outcome): RedirectResponse
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:255']]);

        try {
            $action($data['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$removal->occupancy->user->name} {$outcome}.");
    }

    protected function personRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
        ];
    }

    /**
     * Supervisors may only act on facility users in their own facilities.
     */
    protected function authorizeVisible(Request $request, Occupancy $occupancy): void
    {
        abort_unless(Occupancy::visibleTo($request->user())->whereKey($occupancy->id)->exists(), 403);
    }
}
