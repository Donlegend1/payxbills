<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Changes to a facility user's details (category, amount, address, contact...) asked for by staff
 * other than the admin. Nothing changes until the admin approves.
 */
#[Fillable(['occupancy_id', 'requested_by', 'changes', 'status', 'reviewed_by', 'reviewed_at', 'review_note'])]
class ClientChangeRequest extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['changes' => 'array', 'reviewed_at' => 'datetime'];
    }

    public function occupancy(): BelongsTo
    {
        return $this->belongsTo(Occupancy::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
