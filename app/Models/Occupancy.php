<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\URL;

/**
 * A facility user of a company: where they are, what service they get and what they pay.
 *
 * Every detail is optional. A facility user may hold a space in a facility (hostels, markets) and/or
 * have an address with bins collected a number of times a week (waste collection). They pay their
 * category's monthly amount, so changing a category's amount reprices everyone in it.
 *
 * Invoices and payments belong to the occupancy, so a client's history stays with it.
 */
#[Fillable([
    'user_id', 'facility_id', 'space_id', 'category_id', 'start_date', 'end_date', 'status', 'registered_by',
    'house_no', 'street', 'district', 'bin_type_id', 'bins', 'frequency', 'billing_day',
    'billing_active', 'activated_at', 'deactivated_at',
])]
class Occupancy extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'bins' => 'integer',
            'frequency' => 'integer',
            'billing_day' => 'integer',
            'billing_active' => 'boolean',
            'activated_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /**
     * Edits waiting for the admin's approval (at most one at a time).
     */
    public function changeRequests(): HasMany
    {
        return $this->hasMany(ClientChangeRequest::class);
    }

    public function pendingChange(): HasOne
    {
        return $this->hasOne(ClientChangeRequest::class)->where('status', 'pending')->latestOfMany();
    }

    /**
     * Facility users being billed: on the platform and activated.
     */
    public function scopeBilling(Builder $query): void
    {
        $query->active()->where('billing_active', true);
    }

    /**
     * "active" billing, "pending" (added, never activated) or "deactivated".
     */
    public function billingState(): string
    {
        return match (true) {
            $this->billing_active => 'active',
            $this->activated_at === null => 'pending',
            default => 'deactivated',
        };
    }

    public function binType(): BelongsTo
    {
        return $this->belongsTo(BinType::class);
    }

    /**
     * What this facility user pays a month: their category's amount (nothing without a category).
     */
    public function monthlyAmount(): float
    {
        return (float) ($this->category?->monthly_amount ?? 0);
    }

    /**
     * Day of the month this client is billed: their own, else the company's billing day.
     */
    public function billingDay(): int
    {
        return $this->billing_day ?: (int) Setting::get('billing_day');
    }

    /**
     * "12 Allen Avenue, Ikeja", or null when no address was given.
     */
    public function address(): ?string
    {
        $line = trim(implode(' ', array_filter([$this->house_no, $this->street])));

        return implode(', ', array_filter([$line, $this->district])) ?: null;
    }

    /**
     * Where the client is, for lists, invoices and messages: "Room 12, Green Valley Hostel",
     * else their address, else "—".
     */
    public function placeName(): string
    {
        if ($this->space) {
            return $this->space->name().($this->facility ? ', '.$this->facility->name : '');
        }

        return $this->address() ?? $this->facility?->name ?? '—';
    }

    /**
     * "School", "Standard room"...
     */
    public function categoryName(): ?string
    {
        return $this->category?->name;
    }

    /**
     * Facility name and contacts, with nulls when the client is not in a facility.
     *
     * @return array{name: string|null, address: string|null, phone: string|null, email: string|null}
     */
    public function facilityDetails(): array
    {
        return [
            'name' => $this->facility?->name,
            'address' => $this->facility?->address ?? $this->address(),
            'phone' => $this->facility?->phone,
            'email' => $this->facility?->email,
        ];
    }

    /**
     * "3 Wheeler bins, 2× a week"
     */
    public function serviceSummary(): ?string
    {
        $bins = $this->bins || $this->binType ? trim(($this->bins ?: '').' '.($this->binType?->name ?? 'bins')) : null;
        $often = $this->frequency ? "{$this->frequency}× a week" : null;

        return implode(', ', array_filter([$bins, $often])) ?: null;
    }

    protected static function booted(): void
    {
        static::created(function (Occupancy $occupancy) {
            // Set directly: code is not mass assignable
            $occupancy->forceFill(['code' => 'FU-'.(1000 + $occupancy->id)])->saveQuietly();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function registrar(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function removalRequests(): HasMany
    {
        return $this->hasMany(RemovalRequest::class);
    }

    public function pendingRemoval(): HasOne
    {
        return $this->hasOne(RemovalRequest::class)->where('status', 'pending');
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('status', 'active');
    }

    /**
     * Supervisors only see facility users in the facilities they look after.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isSupervisor()) {
            $query->whereHas('facility', fn (Builder $q) => $q->where('supervisor_id', $user->id));
        } elseif ($user->isFacilityUser()) {
            $query->where('user_id', $user->id);
        }
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    /**
     * The last month fully paid with no unpaid month before it, or null if nothing is paid yet.
     */
    public function paidThrough(): ?Carbon
    {
        $invoices = $this->invoices()->where('status', '!=', 'cancelled')->orderBy('period')->get(['period', 'status']);
        $last = null;

        foreach ($invoices as $invoice) {
            if ($invoice->status !== 'paid') {
                break;
            }
            $last = $invoice->period;
        }

        return $last ? Carbon::createFromFormat('Y-m-d', $last.'-01')->startOfDay() : null;
    }

    public function outstanding(): float
    {
        return round((float) $this->invoices()->open()->sum('amount') - (float) $this->invoices()->open()->sum('amount_paid'), 2);
    }

    /**
     * Pay page sent in emails and SMS. Signed, so the facility user can pay without logging in.
     */
    public function payUrl(): string
    {
        return URL::signedRoute('pay.show', $this);
    }
}
