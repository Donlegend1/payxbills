<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Proof for a manual payment (teller, transfer screenshot, cheque scan). Stored on the private "local" disk.
 */
#[Fillable(['payment_id', 'path', 'original_name', 'mime', 'size', 'uploaded_by'])]
class PaymentAttachment extends Model
{
    use BelongsToCompany;

    public const DISK = 'local';

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
