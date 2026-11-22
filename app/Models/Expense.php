<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An expense memo, optionally charged to a facility, split over one or more categories (lines).
 *
 * amount is what was requested, approved_amount what was approved. A memo can be approved,
 * partially approved (only the approved categories, at their approved amounts, count as spend),
 * kept in view (KIV, to decide later) or rejected.
 */
#[Fillable(['spent_on', 'description', 'category', 'facility_id', 'amount', 'approved_amount', 'memo_path', 'memo_name', 'status', 'requested_by', 'reviewed_by', 'reviewed_at', 'review_note'])]
class Expense extends Model
{
    use BelongsToCompany;

    public const CATEGORIES = ['Supplies', 'Equipment', 'Fuel and transport', 'Staff', 'Uniforms', 'Other'];

    public const STATUSES = [
        'pending' => 'Pending',
        'approved' => 'Approved',
        'partially_approved' => 'Partially approved',
        'kiv' => 'KIV',
        'rejected' => 'Rejected',
    ];

    /** Still waiting for a decision. */
    public const REVIEWABLE = ['pending', 'kiv'];

    /** Count as spend (their approved lines only). */
    public const RECORDED = ['approved', 'partially_approved'];

    /** Picture or scan of the memo, on the private disk. */
    public const DISK = 'local';

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'approved_amount' => 'decimal:2',
            'spent_on' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (Expense $expense) {
            // Set directly: memo_number is not mass assignable
            $expense->forceFill(['memo_number' => sprintf('MEM-%04d', $expense->id)])->saveQuietly();
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(ExpenseLine::class)->orderBy('id');
    }

    /**
     * Memos that count as spend: approved or partially approved.
     */
    public function scopeApproved(Builder $query): void
    {
        $query->whereIn('status', self::RECORDED);
    }

    public function scopeReviewable(Builder $query): void
    {
        $query->whereIn('status', self::REVIEWABLE);
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

    public function isReviewable(): bool
    {
        return in_array($this->status, self::REVIEWABLE, true);
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }
}
