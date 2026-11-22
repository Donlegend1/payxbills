<?php

namespace App\Http\Controllers;

use App\Models\BinType;
use App\Models\Category;
use App\Models\ClientChangeRequest;
use App\Models\Facility;
use App\Models\Occupancy;
use App\Models\RemovalRequest;
use App\Models\Space;
use App\Models\User;
use App\Services\ClientImport;
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
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Facility users: registered by supervisors (or the super admin) into a vacant space,
 * removed only after the super admin approves.
 */
class FacilityUserController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $filters = $request->only('status', 'facility', 'category', 'bin', 'district', 'q');
        $overdue = fn (Builder $q) => $q->overdue();
        $status = $filters['status'] ?? '';

        $occupancies = Occupancy::query()
            ->visibleTo($user)
            ->with(['user:id,name,email,phone,is_active', 'facility:id,name', 'space:id,number,label', 'category:id,name,monthly_amount', 'binType:id,name', 'pendingRemoval.requester:id,name', 'pendingChange.requester:id,name'])
            ->withCount(['invoices as overdue_count' => $overdue])
            ->withSum(['invoices as open_amount' => fn ($q) => $q->open()], 'amount')
            ->withSum(['invoices as open_paid' => fn ($q) => $q->open()], 'amount_paid')
            ->when($status === 'removed', fn (Builder $q) => $q->where('status', 'ended'), fn (Builder $q) => $q->active())
            ->when($status === 'green', fn (Builder $q) => $q->whereDoesntHave('invoices', $overdue))
            ->when($status === 'amber', fn (Builder $q) => $q->whereHas('invoices', $overdue, '=', 1))
            ->when($status === 'red', fn (Builder $q) => $q->whereHas('invoices', $overdue, '>', 1))
            ->when($status === 'removal', fn (Builder $q) => $q->whereHas('pendingRemoval'))
            ->when($status === 'changes', fn (Builder $q) => $q->whereHas('pendingChange'))
            ->when($status === 'billing', fn (Builder $q) => $q->where('billing_active', true))
            ->when($status === 'not-activated', fn (Builder $q) => $q->where('billing_active', false)->whereNull('activated_at'))
            ->when($status === 'deactivated', fn (Builder $q) => $q->where('billing_active', false)->whereNotNull('activated_at'))
            ->when($filters['facility'] ?? null, fn (Builder $q, $id) => $q->where('facility_id', $id))
            ->when($filters['category'] ?? null, fn (Builder $q, $id) => $q->where('category_id', $id))
            ->when($filters['bin'] ?? null, fn (Builder $q, $id) => $q->where('bin_type_id', $id))
            ->when($filters['district'] ?? null, fn (Builder $q, $d) => $q->where('district', $d))
            ->when(trim($filters['q'] ?? ''), fn (Builder $q, string $s) => $this->search($q, $s))
            ->orderBy(Facility::select('name')->whereColumn('facilities.id', 'occupancies.facility_id'))
            ->orderBy(Space::select('number')->whereColumn('spaces.id', 'occupancies.space_id'))
            ->orderBy('district')->orderBy('street')->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (Occupancy $o) => [
                'id' => $o->id,
                'code' => $o->code,
                'user_id' => $o->user_id,
                'name' => $o->user->name,
                'email' => $o->user->email,
                'phone' => $o->user->phone,
                // Every detail, for the edit form
                ...$o->only(FacilityUserService::CLIENT_FIELDS),
                'facility' => $o->facility?->name,
                'space' => $o->space?->name(),
                'place' => $o->placeName(),
                'category' => $o->categoryName(),
                'service' => $o->serviceSummary(),
                'monthly' => $o->monthlyAmount(),
                'start_date' => $o->start_date->toDateString(),
                'ended' => $o->end_date?->format('j M Y'),
                'is_active' => $o->isActive(),
                'paid_through' => $o->paidThrough()?->format('M Y'),
                'outstanding' => round((float) $o->open_amount - (float) $o->open_paid, 2),
                'overdue_count' => $o->overdue_count,
                'billing' => $o->billingState(),
                'activated' => $o->activated_at?->format('j M Y'),
                'change' => $o->pendingChange ? [
                    'id' => $o->pendingChange->id,
                    'by' => $o->pendingChange->requester?->name,
                    'at' => $o->pendingChange->created_at->format('j M Y'),
                    'fields' => app(FacilityUserService::class)->describe($o, $o->pendingChange->changes),
                ] : null,
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
            'binTypes' => BinType::active()->orderBy('name')->get(['id', 'name']),
            'districts' => Occupancy::whereNotNull('district')->distinct()->orderBy('district')->pluck('district'),
            'pendingChanges' => ClientChangeRequest::where('status', 'pending')
                ->whereHas('occupancy', fn (Builder $q) => $q->visibleTo($user))
                ->count(),
            'notActivated' => Occupancy::active()->visibleTo($user)->where('billing_active', false)->whereNull('activated_at')->count(),
            'pendingRemovals' => RemovalRequest::where('status', 'pending')
                ->whereHas('occupancy', fn (Builder $q) => $q->visibleTo($user))
                ->count(),
        ]);
    }

    /**
     * Enrol or update clients from an Excel or CSV file (see ClientImport for the layout).
     */
    public function import(Request $request, ClientImport $import): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:10240'],
            'activate' => ['boolean'],
        ], ['file.mimes' => 'Upload an Excel (.xlsx) or CSV file.']);

        // Only the admin may start billing
        $activate = (bool) ($data['activate'] ?? false) && $request->user()->can('activate-billing');

        $file = $data['file'];

        try {
            $result = $import->import($file->getRealPath(), $file->getClientOriginalExtension(), $request->user(), $activate);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        $summary = "{$result['created']} facility user(s) added".($activate ? ' and activated' : ' (activate them to start billing)').", {$result['updated']} updated."
            .($result['pending'] ? " Changes to {$result['pending']} wait for the admin's approval." : '');
        $errors = $result['errors'];

        return back()
            ->with($errors ? 'error' : 'success', $errors ? $summary.' '.count($errors).' row(s) were skipped: see the list below.' : $summary)
            ->with('importErrors', array_slice($errors, 0, 100));
    }

    /**
     * An empty sheet in the import layout, with one example row.
     */
    public function template(ClientImport $import): BinaryFileResponse
    {
        $path = tempnam(sys_get_temp_dir(), 'pxb').'.xlsx';
        $import->export($path);

        return response()->download($path, 'clients-template.xlsx')->deleteFileAfterSend();
    }

    /**
     * Every active client this user can see, in the import layout: edit in Excel and upload again.
     */
    public function export(Request $request, ClientImport $import): BinaryFileResponse
    {
        $clients = Occupancy::active()->visibleTo($request->user())
            ->with(['user:id,name,email,phone', 'facility:id,name', 'category:id,name,monthly_amount', 'binType:id,name'])
            ->orderBy('id')->get();

        $path = tempnam(sys_get_temp_dir(), 'pxb').'.xlsx';
        $import->export($path, $clients);

        return response()->download($path, 'clients-'.today()->toDateString().'.xlsx')->deleteFileAfterSend();
    }

    public function store(Request $request, FacilityUserService $service): RedirectResponse
    {
        $data = $request->validate([
            ...FacilityUserService::rules(),
            'email' => [...FacilityUserService::rules()['email'], $this->emailRule()],
        ]);

        $returning = filled($data['email'] ?? null) && User::acrossCompanies()->where('email', $data['email'])->exists();

        try {
            $occupancy = $service->register($data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['space_id' => $e->getMessage()]);
        }

        $done = $returning ? 're-registered (returning client, earlier history kept)' : 'registered';
        $login = $occupancy->user->email ? " Login details were emailed to {$occupancy->user->email}." : '';

        return back()->with('success', "{$occupancy->user->name} {$done} ({$occupancy->code}).{$login}");
    }

    public function update(Request $request, Occupancy $occupancy, FacilityUserService $service): RedirectResponse
    {
        $this->authorizeVisible($request, $occupancy);

        if (! $occupancy->isActive()) {
            return back()->with('error', 'This facility user has been removed.');
        }

        $data = $request->validate([
            ...FacilityUserService::rules(),
            'email' => [...FacilityUserService::rules()['email'], $this->emailRule($occupancy->user_id)],
        ]);

        try {
            $change = $service->requestChange($occupancy, $data, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['space_id' => $e->getMessage()]);
        }

        return back()->with('success', $change
            ? "Changes to {$occupancy->user->name} sent to the admin. They take effect once approved."
            : "{$occupancy->user->name} updated.");
    }

    public function activate(Request $request, Occupancy $occupancy, FacilityUserService $service): RedirectResponse
    {
        try {
            $service->activate($occupancy, $request->user());
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$occupancy->user->name} activated. Billing has started and their pay link has been sent.");
    }

    public function deactivate(Request $request, Occupancy $occupancy, FacilityUserService $service): RedirectResponse
    {
        try {
            $service->deactivate($occupancy);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "{$occupancy->user->name} deactivated. They will not be billed until activated again.");
    }

    public function approveChange(Request $request, ClientChangeRequest $change, FacilityUserService $service): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;

        try {
            $service->approveChange($change, $request->user(), $note);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Changes to {$change->occupancy->user->name} approved and applied.");
    }

    public function rejectChange(Request $request, ClientChangeRequest $change, FacilityUserService $service): RedirectResponse
    {
        $note = $request->validate(['note' => ['nullable', 'string', 'max:255']])['note'] ?? null;

        try {
            $service->rejectChange($change, $request->user(), $note);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', "Changes to {$change->occupancy->user->name} rejected. Nothing was changed.");
    }

    /**
     * Find facility users by any detail: name, contacts, account, address, facility and space,
     * category (name or amount), bin type, number of bins or frequency.
     */
    protected function search(Builder $query, string $s): void
    {
        $like = "%{$s}%";
        $named = fn (string $relation, string $column = 'name') => fn (Builder $w) => $w->orWhereHas($relation, fn (Builder $r) => $r->where($column, 'like', $like));

        $query->where(function (Builder $w) use ($like, $s, $named) {
            $w->where('code', 'like', $like)
                ->orWhereHas('user', fn (Builder $u) => $u->where('name', 'like', $like)->orWhere('email', 'like', $like)->orWhere('phone', 'like', $like))
                ->orWhere('house_no', 'like', $like)
                ->orWhere('street', 'like', $like)
                ->orWhere('district', 'like', $like)
                ->orWhereHas('space', fn (Builder $sp) => $sp->where('number', 'like', $like)->orWhere('label', 'like', $like));

            foreach ([['facility'], ['category'], ['binType']] as [$relation]) {
                $named($relation)($w);
            }

            // "3" finds 3 bins or 3 times a week; "15000" finds everyone whose category costs that
            if (is_numeric($s)) {
                $w->orWhere('bins', (int) $s)->orWhere('frequency', (int) $s)
                    ->orWhereHas('category', fn (Builder $c) => $c->where('monthly_amount', (float) $s));
            }
        });
    }

    public function resendLogin(Request $request, Occupancy $occupancy, FacilityUserService $service): RedirectResponse
    {
        $this->authorizeVisible($request, $occupancy);

        if (! $occupancy->isActive()) {
            return back()->with('error', 'This facility user has been removed.');
        }

        try {
            $service->resendLogin($occupancy->user);
        } catch (InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

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
            ? "{$occupancy->user->name} removed.".($occupancy->space ? " {$occupancy->space->name()} is now vacant." : '')
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

    protected function emailRule(?int $userId = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($userId) {
            if ($problem = FacilityUserService::emailProblem($value, $userId)) {
                $fail($problem);
            }
        };
    }

    /**
     * Supervisors may only act on facility users in their own facilities.
     */
    protected function authorizeVisible(Request $request, Occupancy $occupancy): void
    {
        abort_unless(Occupancy::visibleTo($request->user())->whereKey($occupancy->id)->exists(), 403);
    }
}
