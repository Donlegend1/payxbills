<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\BinType;
use App\Models\Category;
use App\Models\ClientChangeRequest;
use App\Models\Facility;
use App\Models\Occupancy;
use App\Models\RemovalRequest;
use App\Models\Space;
use App\Models\User;
use App\Tenancy\CompanyRules;
use App\Support\Money;
use App\Tenancy\CurrentCompany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The life of a facility user: registered (not billed yet), activated by the admin (billing starts and
 * they get their pay link), edited (other staff's edits wait for the admin's approval), deactivated
 * (billing stops) and removed.
 *
 * Every detail except the name is optional, so facility users can be added from incomplete
 * spreadsheets and filled in later. Facility users do not need to log in: they pay from the link
 * sent each month, and can set a password with "Forgot password" if they want to see their history.
 */
class FacilityUserService
{
    /** Details stored on the occupancy (the rest are on the user: name, email, phone). */
    public const CLIENT_FIELDS = [
        'facility_id', 'space_id', 'category_id', 'house_no', 'street', 'district',
        'bin_type_id', 'bins', 'frequency', 'billing_day',
    ];

    public function __construct(protected BillingService $billing, protected Messenger $messenger) {}

    /**
     * Validation rules for a client, shared by the form and the spreadsheet import.
     * Email uniqueness is checked separately (see FacilityUserController).
     *
     * @return array<string, array<int, mixed>>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^\+?[0-9 ()-]{7,20}$/'],
            'house_no' => ['nullable', 'string', 'max:50'],
            'street' => ['nullable', 'string', 'max:255'],
            'district' => ['nullable', 'string', 'max:100'],
            'bin_type_id' => ['nullable', CompanyRules::exists('bin_types')],
            'bins' => ['nullable', 'integer', 'min:0', 'max:1000'],
            // Collections per week
            'frequency' => ['nullable', 'integer', 'min:0', 'max:21'],
            'billing_day' => ['nullable', 'integer', 'between:1,28'],
            'facility_id' => ['nullable', CompanyRules::exists('facilities')],
            'space_id' => ['nullable', CompanyRules::exists('spaces')],
            // The category decides what they pay each month
            'category_id' => ['nullable', CompanyRules::exists('categories')],
            'start_date' => ['nullable', 'date'],
        ];
    }

    /**
     * Add a facility user. They are not billed and get no messages until activated
     * (straight away when $activate).
     *
     * A facility user who was removed earlier can be registered again with the same email: their
     * account is reactivated and their earlier occupancies, invoices and payments stay on record.
     *
     * @param  array<string, mixed>  $data  validated with rules()
     */
    public function register(array $data, User $by, bool $activate = false): Occupancy
    {
        // Nobody knows this password: facility users who want to log in set their own with "Forgot password"
        $password = Str::password(32);
        $email = filled($data['email'] ?? null) ? $data['email'] : null;

        $occupancy = DB::transaction(function () use ($data, $by, $password, $email) {
            $place = $this->place($data, $by);

            $user = $email ? User::acrossCompanies()->where('email', $email)->lockForUpdate()->first() : null;

            if ($user && ($user->company_id !== CurrentCompany::id() || ! self::canRejoin($user))) {
                throw new InvalidArgumentException('This email already belongs to an account that is in use.');
            }

            $user ??= new User(['email' => $email, 'role' => Role::FacilityUser]);
            $user->fill([
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'password' => $password,
                'must_change_password' => false,
                'is_active' => true,
            ])->save();

            return Occupancy::create([
                ...$this->details($data),
                ...$place,
                'user_id' => $user->id,
                'start_date' => Carbon::parse($data['start_date'] ?? today()),
                'status' => 'active',
                'billing_active' => false,
                'registered_by' => $by->id,
            ]);
        });

        $occupancy->load(['user', 'facility', 'space', 'category', 'binType']);

        if ($activate) {
            $this->activate($occupancy, $by);
        }

        return $occupancy;
    }

    /**
     * Start billing: send the welcome with the pay link, and bill the current month straight away
     * (when there is an amount) so they can pay from day one. Billing then runs every month.
     */
    public function activate(Occupancy $occupancy, User $by): void
    {
        if (! $occupancy->isActive()) {
            throw new InvalidArgumentException('This facility user has been removed.');
        }

        if ($occupancy->billing_active) {
            throw new InvalidArgumentException("{$occupancy->user->name} is already being billed.");
        }

        $occupancy->update(['billing_active' => true, 'activated_at' => $occupancy->activated_at ?? now(), 'deactivated_at' => null]);

        $this->messenger->activated($occupancy);

        $month = $occupancy->start_date->gt(today()) ? $occupancy->start_date : today();
        if ($occupancy->start_date->lte(today()) && $invoice = $this->billing->issueForPeriod($occupancy, $month, $by)) {
            $this->messenger->invoiceIssued($invoice);
        }
    }

    /**
     * Stop billing: no more monthly invoices or reminders, and unpaid months ahead are cancelled.
     * What they already owe stays owed and can still be paid.
     */
    public function deactivate(Occupancy $occupancy): void
    {
        if (! $occupancy->billing_active) {
            throw new InvalidArgumentException("{$occupancy->user->name} is not being billed.");
        }

        DB::transaction(function () use ($occupancy) {
            $occupancy->update(['billing_active' => false, 'deactivated_at' => now()]);
            $occupancy->invoices()->where('status', 'unpaid')->where('period', '>', today()->format('Y-m'))->update(['status' => 'cancelled']);
        });
    }

    /**
     * Change a facility user's details. The admin's changes apply at once; anyone else's wait for
     * the admin to approve them. Returns the request, or null when applied straight away.
     *
     * @param  array<string, mixed>  $data  validated with rules(); only keys present are changed
     */
    public function requestChange(Occupancy $occupancy, array $data, User $by): ?ClientChangeRequest
    {
        $changes = $this->diff($occupancy, $data);

        if ($changes === []) {
            throw new InvalidArgumentException('Nothing was changed.');
        }

        if ($by->can('approve-changes')) {
            $this->update($occupancy, $changes, $by);

            return null;
        }

        if ($occupancy->pendingChange()->exists()) {
            throw new InvalidArgumentException("Changes to {$occupancy->user->name} are already waiting for the admin's approval.");
        }

        // A space must still be free when the admin approves; check now so the request makes sense
        if (filled($changes['space_id'] ?? null)) {
            $this->vacantSpace((int) $changes['space_id'], $by);
        }

        return $occupancy->changeRequests()->create(['requested_by' => $by->id, 'changes' => $changes, 'status' => 'pending']);
    }

    public function approveChange(ClientChangeRequest $request, User $by, ?string $note = null): void
    {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException('These changes have already been reviewed.');
        }

        DB::transaction(function () use ($request, $by, $note) {
            $this->update($request->occupancy, $request->changes, $by);
            $request->update(['status' => 'approved', 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_note' => $note]);
        });
    }

    public function rejectChange(ClientChangeRequest $request, User $by, ?string $note = null): void
    {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException('These changes have already been reviewed.');
        }

        $request->update(['status' => 'rejected', 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_note' => $note]);
    }

    /**
     * The requested changes in words, for the admin: [[label, from, to], ...].
     *
     * @return list<array{0: string, 1: string, 2: string}>
     */
    public function describe(Occupancy $occupancy, array $changes): array
    {
        $labels = [
            'name' => 'Full name', 'email' => 'Email', 'phone' => 'Phone', 'house_no' => 'House no.', 'street' => 'Street',
            'district' => 'District', 'bin_type_id' => 'Type of bins',
            'bins' => 'No. of bins', 'frequency' => 'Times per week', 'billing_day' => 'Billing day',
            'facility_id' => 'Facility', 'space_id' => 'Space', 'category_id' => 'Category',
        ];

        $show = fn (string $field, mixed $value) => blank($value) ? '—' : match ($field) {
            'bin_type_id' => BinType::find($value)?->name,
            'facility_id' => Facility::find($value)?->name,
            'space_id' => Space::find($value)?->name(),
            'category_id' => ($c = Category::find($value)) ? "{$c->name} (".Money::format($c->monthly_amount).')' : null,
            default => (string) $value,
        } ?? '—';

        $current = $this->current($occupancy);

        return collect($changes)
            ->map(fn ($value, $field) => [$labels[$field] ?? $field, $show($field, $current[$field] ?? null), $show($field, $value)])
            ->values()
            ->all();
    }

    /**
     * Why $email cannot be used for a client, or null when it can. Emails are unique across all
     * companies; a removed client's email may be used again (they rejoin on the same account).
     * $userId is the client being edited, whose own email is of course fine.
     */
    public static function emailProblem(?string $email, ?int $userId = null): ?string
    {
        if (blank($email)) {
            return null;
        }

        $existing = User::acrossCompanies()->where('email', $email)->first();

        return match (true) {
            ! $existing, $existing->id === $userId => null,
            $existing->company_id !== CurrentCompany::id() => 'This email is already registered with another organisation on PayXbills. Use a different email.',
            $userId !== null => 'Another account already uses this email.',
            ! self::canRejoin($existing) => $existing->isFacilityUser()
                ? "{$existing->name} is already an active client. Edit them instead, or remove them first."
                : 'This email belongs to a staff account.',
            default => null,
        };
    }

    /**
     * A removed facility user (no active space) may be registered again. Staff and current facility users may not.
     */
    public static function canRejoin(User $user): bool
    {
        return $user->isFacilityUser() && ! $user->occupancy()->exists();
    }

    /**
     * Apply changes to a facility user's details. Only the keys present in $data change, so a
     * spreadsheet with a few columns updates just those. A new amount or category applies from the
     * next month billed. Use requestChange() for changes that may need approval.
     *
     * @param  array<string, mixed>  $data  validated with rules()
     */
    public function update(Occupancy $occupancy, array $data, User $by): void
    {
        DB::transaction(function () use ($occupancy, $data, $by) {
            $occupancy->user->update(array_intersect_key($data, array_flip(['name', 'email', 'phone'])));

            $changes = $this->details($data);

            // Naming the client's current facility without a space (e.g. a spreadsheet) keeps their space
            $sameFacilityOnly = ! array_key_exists('space_id', $data) && (int) ($data['facility_id'] ?? 0) === (int) $occupancy->facility_id;

            if (! $sameFacilityOnly && (array_key_exists('space_id', $data) || array_key_exists('facility_id', $data))) {
                $spaceId = $data['space_id'] ?? null;
                $changes = [...$changes, ...((int) $spaceId === (int) $occupancy->space_id && $spaceId
                    ? ['space_id' => $occupancy->space_id, 'facility_id' => $occupancy->facility_id]
                    : $this->place($data, $by))];
            }

            $occupancy->update($changes);
        });
    }

    /**
     * New password, emailed. The old one stops working.
     */
    public function resendLogin(User $user): void
    {
        if (! $user->email) {
            throw new InvalidArgumentException("{$user->name} has no email address. Add one first.");
        }

        $password = Str::password(12, symbols: false);
        $user->update(['password' => $password, 'must_change_password' => true]);
        $this->messenger->welcome($user, $password, $user->occupancy);
    }

    /**
     * The fields in $data whose value differs from what is stored now.
     *
     * @return array<string, mixed>
     */
    protected function diff(Occupancy $occupancy, array $data): array
    {
        $current = $this->current($occupancy);
        $fields = [...self::CLIENT_FIELDS, 'name', 'email', 'phone'];
        $norm = fn ($v) => $v === null || $v === '' ? null : (is_numeric($v) ? (string) (float) $v : (string) $v);

        return collect(array_intersect_key($data, array_flip($fields)))
            ->map(fn ($v) => $v === '' ? null : $v)
            ->filter(fn ($v, $field) => $norm($v) !== $norm($current[$field] ?? null))
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function current(Occupancy $occupancy): array
    {
        return [
            ...$occupancy->only(self::CLIENT_FIELDS),
            'name' => $occupancy->user->name,
            'email' => $occupancy->user->email,
            'phone' => $occupancy->user->phone,
        ];
    }

    /**
     * The occupancy details given in $data (address, bins, amount...), blanks stored as null.
     *
     * @return array<string, mixed>
     */
    protected function details(array $data): array
    {
        $fields = array_diff(self::CLIENT_FIELDS, ['facility_id', 'space_id']);

        return array_map(fn ($v) => $v === '' ? null : $v, array_intersect_key($data, array_flip($fields)));
    }

    /**
     * Facility and space: a vacant space (which decides the facility), a facility alone, or neither.
     * Supervisors must place clients in a facility they look after, or they could not see them.
     *
     * @return array{facility_id: int|null, space_id: int|null}
     */
    protected function place(array $data, User $by): array
    {
        if (filled($data['space_id'] ?? null)) {
            $space = $this->vacantSpace((int) $data['space_id'], $by);

            return ['facility_id' => $space->facility_id, 'space_id' => $space->id];
        }

        $facilityId = filled($data['facility_id'] ?? null) ? (int) $data['facility_id'] : null;

        if ($by->isSupervisor() && ! $by->facilities()->whereKey($facilityId)->exists()) {
            throw new InvalidArgumentException('Choose one of the facilities you supervise.');
        }

        return ['facility_id' => $facilityId, 'space_id' => null];
    }

    /**
     * Ask to remove a facility user. A super admin's own request is approved straight away.
     */
    public function requestRemoval(Occupancy $occupancy, User $by, string $reason): RemovalRequest
    {
        if (! $occupancy->isActive()) {
            throw new InvalidArgumentException('This facility user has already been removed.');
        }

        if ($occupancy->pendingRemoval()->exists()) {
            throw new InvalidArgumentException('A removal request is already waiting for approval.');
        }

        $request = $occupancy->removalRequests()->create(['requested_by' => $by->id, 'reason' => $reason, 'status' => 'pending']);

        if ($by->can('approve-removals')) {
            $this->approveRemoval($request, $by);
        }

        return $request->refresh();
    }

    /**
     * End the occupancy: the space becomes vacant, unpaid months ahead are cancelled and the login is disabled.
     */
    public function approveRemoval(RemovalRequest $request, User $by, ?string $note = null): void
    {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException('This request has already been reviewed.');
        }

        DB::transaction(function () use ($request, $by, $note) {
            $request->update(['status' => 'approved', 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_note' => $note]);

            $occupancy = $request->occupancy;
            $occupancy->update(['status' => 'ended', 'end_date' => today()]);

            $occupancy->invoices()
                ->where('status', 'unpaid')
                ->where('period', '>', today()->format('Y-m'))
                ->update(['status' => 'cancelled']);

            $occupancy->user->update(['is_active' => false]);
        });
    }

    public function rejectRemoval(RemovalRequest $request, User $by, ?string $note = null): void
    {
        if ($request->status !== 'pending') {
            throw new InvalidArgumentException('This request has already been reviewed.');
        }

        $request->update(['status' => 'rejected', 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'review_note' => $note]);
    }

    /**
     * Lock the space and check it is free and in a facility this user may register into.
     */
    protected function vacantSpace(int $spaceId, User $by): Space
    {
        $space = Space::with('facility')->lockForUpdate()->findOrFail($spaceId);

        if ($by->isSupervisor() && $space->facility->supervisor_id !== $by->id) {
            throw new InvalidArgumentException('You can only register facility users in facilities you supervise.');
        }

        if (! $space->is_active || ! $space->facility->is_active) {
            throw new InvalidArgumentException("Space {$space->name()} is not available.");
        }

        if ($space->occupancies()->where('status', 'active')->exists()) {
            throw new InvalidArgumentException("Space {$space->name()} at {$space->facility->name} is already occupied.");
        }

        return $space;
    }
}
