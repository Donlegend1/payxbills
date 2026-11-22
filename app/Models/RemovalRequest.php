<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A supervisor's request to remove a facility user from their facility. Only the super admin can approve it.
 */
#[Fillable(['occupancy_id', 'requested_by', 'reason', 'status', 'reviewed_by', 'reviewed_at', 'review_note'])]
class RemovalRequest extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['reviewed_at' => 'datetime'];
    }

    public function occupancy(): BelongsTo
    {
        return $this->belongsTo(Occupancy::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
