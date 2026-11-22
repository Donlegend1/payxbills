<?php

namespace App\Services;

use App\Models\Payment;
use App\Models\PaymentAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/**
 * Stores proof of payment (teller, transfer screenshot, cheque scan) on the private disk.
 */
class PaymentProof
{
    /** Validation rules for one uploaded file: images or PDF, up to 5 MB. */
    public const RULES = ['file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'];

    /**
     * @param  array<int, UploadedFile>  $files
     */
    public function store(Payment $payment, array $files, User $by): void
    {
        foreach ($files as $file) {
            PaymentAttachment::create([
                'payment_id' => $payment->id,
                'path' => $file->store("payment-proof/{$payment->id}", PaymentAttachment::DISK),
                'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'mime' => $file->getMimeType(),
                'size' => $file->getSize(),
                'uploaded_by' => $by->id,
            ]);
        }
    }
}
