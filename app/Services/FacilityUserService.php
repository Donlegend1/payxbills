<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\Occupancy;
use App\Models\RemovalRequest;
use App\Models\Space;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Registering facility users into a space, and removing them (supervisor requests, super admin approves).
 */
class FacilityUserService
{
    public function __construct(protected BillingService $billing, protected Messenger $messenger) {}

    /**
     * Create the login, place the user in a vacant space, bill the first month and email the login details.
     *
     * A facility user who was removed earlier (account deactivated, no active space) can be registered
     * again, in the same or another facility: their account is reactivated and their earlier
     * occupancies, invoices and payments stay on record.
     *
     * @param  array{name: string, email: string, phone?: string|null, space_id: int, category_id: int, start_date?: mixed}  $data
     */
    public function register(array $data, User $by): Occupancy
    {
        $password = Str::password(12, symbols: false);

        $occupancy = DB::transaction(function () use ($data, $by, $password) {
            $space = $this->vacantSpace((int) $data['space_id'], $by);

            $user = User::acrossCompanies()->where('email', $data['email'])->lockForUpdate()->first();

            if ($user && ($user->company_id !== $by->company_id || ! self::canRejoin($user))) {
                throw new InvalidArgumentException('This email already belongs to an account that is in use.');
            }

            $user ??= new User(['email' => $data['email'], 'role' => Role::FacilityUser]);
            $user->fill([
                'name' => $data['name'],
                'phone' => $data['phone'] ?? null,
                'password' => $password,
                'must_change_password' => true,
                'is_active' => true,
            ])->save();

            return Occupancy::create([
                'user_id' => $user->id,
                'facility_id' => $space->facility_id,
                'space_id' => $space->id,
                'category_id' => $data['category_id'],
                'start_date' => Carbon::parse($data['start_date'] ?? today()),
                'status' => 'active',
                'registered_by' => $by->id,
            ]);
        });

        $occupancy->load(['user', 'facility', 'space', 'category']);
        $this->messenger->welcome($occupancy->user, $password, $occupancy);

        // Bill the first month straight away so the user can pay from day one
        if ($occupancy->start_date->lte(today()) && $invoice = $this->billing->issueForPeriod($occupancy, $occupancy->start_date)) {
            $this->messenger->invoiceIssued($invoice);
        }

        return $occupancy;
    }

    /**
     * A removed facility user (no active space) may be registered again. Staff and current facility users may not.
     */
    public static function canRejoin(User $user): bool
    {
        return $user->isFacilityUser() && ! $user->occupancy()->exists();
    }

    /**
     * Move a facility user to another vacant space and/or category. Future months use the new category.
     */
    public function update(Occupancy $occupancy, array $data, User $by): void
    {
        DB::transaction(function () use ($occupancy, $data, $by) {
            $occupancy->user->update([
                'name' => $data['name'],
                'email' => $data['email'],
                'phone' => $data['phone'] ?? null,
            ]);

            $changes = ['category_id' => $data['category_id']];

            if ((int) $data['space_id'] !== $occupancy->space_id) {
                $space = $this->vacantSpace((int) $data['space_id'], $by);
                $changes += ['space_id' => $space->id, 'facility_id' => $space->facility_id];
            }

            $occupancy->update($changes);
        });
    }

    /**
     * New password, emailed. The old one stops working.
     */
    public function resendLogin(User $user): void
    {
        $password = Str::password(12, symbols: false);
        $user->update(['password' => $password, 'must_change_password' => true]);
        $this->messenger->welcome($user, $password, $user->occupancy);
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
