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
 * A facility user's place in a facility: their space and billing category.
 * Invoices and payments belong to the occupancy, so a user's history stays with the space they held.
 */
#[Fillable(['user_id', 'facility_id', 'space_id', 'category_id', 'start_date', 'end_date', 'status', 'registered_by'])]
class Occupancy extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['start_date' => 'date', 'end_date' => 'date'];
    }

    protected static function booted(): void
    {
        static::created(function (Occupancy $occupancy) {
            $occupancy->updateQuietly(['code' => 'FU-'.(1000 + $occupancy->id)]);
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
