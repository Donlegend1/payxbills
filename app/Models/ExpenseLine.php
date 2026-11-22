<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One category on an expense memo, with what was requested and what was approved.
 * Only approved lines of approved or partially approved memos count as spend.
 */
#[Fillable(['expense_id', 'category', 'amount', 'approved_amount'])]
class ExpenseLine extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'approved_amount' => 'decimal:2'];
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(Expense::class);
    }

    /**
     * Lines that count as spend: approved, on an approved or partially approved memo.
     */
    public function scopeRecorded(\Illuminate\Database\Eloquent\Builder $query): void
    {
        // Qualified: expenses has an approved_amount column too when joined
        $query->whereNotNull($query->qualifyColumn('approved_amount'))->whereHas('expense', fn ($q) => $q->approved());
    }
}
