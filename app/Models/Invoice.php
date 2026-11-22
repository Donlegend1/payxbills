<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One month's dues for one occupancy.
 */
#[Fillable(['number', 'occupancy_id', 'period', 'description', 'amount', 'amount_paid', 'issue_date', 'due_date', 'status', 'sent_at', 'nrs_copy', 'paid_at', 'created_by'])]
class Invoice extends Model
{
    use BelongsToCompany;

    public const OPEN = ['unpaid', 'partial'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'issue_date' => 'date',
            'due_date' => 'date',
            'paid_at' => 'date',
            'sent_at' => 'datetime',
            'nrs_copy' => 'boolean',
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

    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', self::OPEN);
    }

    /**
     * Open invoices past their due date plus the grace days in settings.
     */
    public function scopeOverdue(Builder $query): void
    {
        $query->open()->whereDate('due_date', '<', self::overdueCutoff());
    }

    public function scopeVisibleTo(Builder $query, User $user): void
    {
        $query->whereHas('occupancy', fn (Builder $q) => $q->visibleTo($user));
    }

    public static function overdueCutoff(): Carbon
    {
        return today()->subDays((int) Setting::get('grace_days'));
    }

    public function balance(): float
    {
        return round((float) $this->amount - (float) $this->amount_paid, 2);
    }

    public function isOverdue(): bool
    {
        return in_array($this->status, self::OPEN, true) && $this->due_date->lt(self::overdueCutoff());
    }

    /**
     * Status shown to people: the stored status, or "overdue" for late open invoices.
     */
    public function displayStatus(): string
    {
        return $this->isOverdue() ? 'Overdue' : ucfirst($this->status);
    }

    public function daysOverdue(): int
    {
        return max(0, (int) $this->due_date->diffInDays(today(), false));
    }

    /**
     * "March 2027"
     */
    public function periodLabel(): string
    {
        return Carbon::createFromFormat('Y-m-d', $this->period.'-01')->format('F Y');
    }
}
