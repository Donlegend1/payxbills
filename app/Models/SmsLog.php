<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One SMS sent (or attempted) to one phone number, whatever part of the app sent it.
 */
#[Fillable(['recipient', 'sender_id', 'message', 'driver', 'status', 'provider_message_id', 'provider_status', 'provider_status_code', 'cost', 'error', 'attempts', 'related_type', 'related_id', 'user_id', 'sent_at', 'delivered_at'])]
class SmsLog extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'provider_status_code' => 'integer',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * The record this SMS was about (a MessageLog, Invoice, User, ...), if any.
     */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Who triggered the send, when it came from a logged-in user.
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function failed(): bool
    {
        return $this->status === 'failed';
    }

    public function scopeFailed(Builder $query): void
    {
        $query->where('status', 'failed');
    }
}
