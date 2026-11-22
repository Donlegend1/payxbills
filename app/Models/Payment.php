<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;

#[Fillable(['receipt_number', 'occupancy_id', 'amount', 'months', 'method', 'source', 'reference', 'gateway_reference', 'paid_on', 'status', 'recorded_by', 'reviewed_by', 'reviewed_at', 'rejection_reason'])]
class Payment extends Model
{
    use BelongsToCompany;

    public const METHODS = ['online' => 'Online (Paystack)', 'bank_transfer' => 'Bank transfer', 'cash' => 'Cash', 'pos' => 'POS', 'cheque' => 'Cheque'];

    /** Methods for payments recorded by hand. */
    public const MANUAL_METHODS = ['bank_transfer', 'cash', 'pos', 'cheque'];

    public const STATUSES = ['pending' => 'Pending', 'under_review' => 'Under Review', 'confirmed' => 'Confirmed', 'rejected' => 'Rejected'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'months' => 'integer',
            'paid_on' => 'date',
            'reviewed_at' => 'datetime',
        ];
    }

    public function occupancy(): BelongsTo
    {
        return $this->belongsTo(Occupancy::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(PaymentAttachment::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopeConfirmed(Builder $query): void
    {
        $query->where('status', 'confirmed');
    }

    /**
     * Supervisors see payments in their facilities; facility users see their own.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereHas('occupancy', fn (Builder $q) => $q->visibleTo($user));
    }

    public function sourceLabel(): string
    {
        return match (true) {
            $this->source === 'online' => 'Paystack',
            $this->recorder === null => 'Manual',
            $this->recorder->isFacilityUser() => 'Submitted by facility user',
            default => $this->recorder->name,
        };
    }

    /**
     * Receipt page linked from receipt emails and SMS. Signed, so it opens without logging in.
     */
    public function receiptUrl(): string
    {
        return URL::signedRoute('receipts.public', $this);
    }
}
