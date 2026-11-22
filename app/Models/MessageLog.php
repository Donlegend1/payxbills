<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One email or SMS sent (or attempted) to a facility user.
 */
#[Fillable(['user_id', 'invoice_id', 'payment_id', 'channel', 'type', 'recipient', 'subject', 'message', 'status', 'error', 'attempts', 'sent_at'])]
class MessageLog extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['sent_at' => 'datetime', 'attempts' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
