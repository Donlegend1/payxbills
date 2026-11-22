<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An expense memo charged to a facility.
 */
#[Fillable(['spent_on', 'description', 'category', 'facility_id', 'amount', 'status', 'requested_by', 'reviewed_by', 'reviewed_at'])]
class Expense extends Model
{
    use BelongsToCompany;

    public const CATEGORIES = ['Supplies', 'Equipment', 'Fuel and transport', 'Staff', 'Uniforms', 'Other'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'spent_on' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Expense $expense) {
            $expense->updateQuietly(['memo_number' => sprintf('MEM-%04d', $expense->id)]);
        });
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function scopeApproved(Builder $query): void
    {
        $query->where('status', 'approved');
    }

    /**
     * Supervisors only see the memos they raised.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isSupervisor()) {
            $query->where('requested_by', $user->id);
        }
    }
}
