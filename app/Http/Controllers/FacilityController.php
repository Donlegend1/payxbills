<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\Facility;
use App\Models\Space;
use App\Models\User;
use App\Tenancy\CompanyRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Facilities and their spaces. The super admin manages them; other staff can look.
 */
class FacilityController extends Controller
{
    /** Most spaces added in one go from a number range. */
    protected const MAX_RANGE = 500;

    public function index(Request $request): Response
    {
        $facilities = Facility::query()
            ->visibleTo($request->user())
            ->with('supervisor:id,name')
            ->withCount(['spaces', 'spaces as vacant_count' => fn ($q) => $q->vacant(), 'occupancies as occupied_count' => fn ($q) => $q->active()])
            ->orderBy('name')
            ->get()
            ->map(fn (Facility $f) => [
                ...$f->only('id', 'name', 'address', 'phone', 'email', 'supervisor_id', 'is_active'),
                'supervisor' => $f->supervisor?->name,
                'spaces' => $f->spaces_count,
                'vacant' => $f->vacant_count,
                'occupied' => $f->occupied_count,
            ]);

        return Inertia::render('App/Facilities', [
            'facilities' => $facilities,
            'supervisors' => $this->supervisors(),
        ]);
    }

    public function show(Request $request, Facility $facility): Response
    {
        abort_unless(Facility::visibleTo($request->user())->whereKey($facility->id)->exists(), 403);

        $facility->load('supervisor:id,name');

        $spaces = $facility->spaces()
            ->with(['currentOccupancy.user:id,name', 'currentOccupancy.category:id,name'])
            ->orderByRaw('length(number), number')
            ->get()
            ->map(fn (Space $s) => [
                'id' => $s->id,
                'number' => $s->number,
                'label' => $s->label,
                'is_active' => $s->is_active,
                'occupant' => $s->currentOccupancy ? [
                    'id' => $s->currentOccupancy->id,
                    'name' => $s->currentOccupancy->user->name,
                    'code' => $s->currentOccupancy->code,
                    'category' => $s->currentOccupancy->category->name,
                ] : null,
            ]);

        return Inertia::render('App/FacilityShow', [
            'facility' => [
                ...$facility->only('id', 'name', 'address', 'phone', 'email', 'supervisor_id', 'is_active'),
                'supervisor' => $facility->supervisor?->name,
            ],
            'spaces' => $spaces,
            'supervisors' => $this->supervisors(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $facility = Facility::create($this->validated($request));

        return redirect()->route('facilities.show', $facility)->with('success', "{$facility->name} created. Add its rooms or spaces next.");
    }

    public function update(Request $request, Facility $facility): RedirectResponse
    {
        $facility->update($this->validated($request));

        return back()->with('success', "{$facility->name} updated.");
    }

    /**
     * Add one space, or a numbered range (e.g. 101 to 120), skipping numbers that already exist.
     */
    public function storeSpaces(Request $request, Facility $facility): RedirectResponse
    {
        $data = $request->validate([
            'label' => ['nullable', 'string', 'max:50'],
            'number' => ['required_without:from', 'nullable', 'string', 'max:30'],
            'from' => ['required_without:number', 'nullable', 'integer', 'min:0'],
            'to' => ['required_with:from', 'nullable', 'integer', 'gte:from'],
            'prefix' => ['nullable', 'string', 'max:10'],
        ]);

        if (filled($data['number'] ?? null)) {
            $numbers = [trim($data['number'])];
        } else {
            if ($data['to'] - $data['from'] + 1 > self::MAX_RANGE) {
                return back()->withErrors(['to' => 'Add at most '.self::MAX_RANGE.' spaces at a time.']);
            }
            $numbers = array_map(fn ($n) => ($data['prefix'] ?? '').$n, range($data['from'], $data['to']));
        }

        $existing = $facility->spaces()->whereIn('number', $numbers)->pluck('number')->all();
        $new = array_values(array_diff($numbers, $existing));

        foreach ($new as $number) {
            $facility->spaces()->create(['number' => $number, 'label' => $data['label'] ?? null]);
        }

        $skipped = count($existing) ? ' '.count($existing).' already existed and were skipped.' : '';

        return back()->with(count($new) ? 'success' : 'error', count($new).' space(s) added.'.$skipped);
    }

    public function updateSpace(Request $request, Space $space): RedirectResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:30', CompanyRules::unique('spaces')->where('facility_id', $space->facility_id)->ignore($space)],
            'label' => ['nullable', 'string', 'max:50'],
            'is_active' => ['boolean'],
        ]);

        if (! ($data['is_active'] ?? true) && $space->currentOccupancy()->exists()) {
            return back()->with('error', "Space {$space->name()} is occupied. Remove the facility user first.");
        }

        $space->update($data);

        return back()->with('success', "Space {$space->name()} updated.");
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'supervisor_id' => ['nullable', CompanyRules::exists('users')->where('role', Role::Supervisor->value)],
            'is_active' => ['boolean'],
        ]);
    }

    protected function supervisors()
    {
        return User::where('role', Role::Supervisor)->where('is_active', true)->orderBy('name')->get(['id', 'name']);
    }
}
