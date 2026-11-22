<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('receipt_number')->nullable()->unique();
            $table->foreignId('occupancy_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            // Months the payer chose to cover. Null for a plain amount.
            $table->unsignedSmallInteger('months')->nullable();
            $table->enum('method', ['online', 'bank_transfer', 'cash', 'pos', 'cheque']);
            // online = Paystack, manual = recorded by staff or submitted by the facility user
            $table->enum('source', ['online', 'manual']);
            // Bank/teller/cheque reference. For manual payments the accountant must supply it to clear.
            $table->string('reference')->nullable();
            // Our Paystack transaction reference
            $table->string('gateway_reference')->nullable()->unique();
            $table->date('paid_on');
            // pending = online checkout started, not paid yet
            $table->enum('status', ['pending', 'under_review', 'confirmed', 'rejected'])->default('under_review');
            $table->foreignId('recorded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['status', 'paid_on']);
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 14, 2);
            $table->timestamps();
        });

        // Proof for manual payments (teller, transfer screenshot...), stored on the private disk
        Schema::create('payment_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name');
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_attachments');
        Schema::dropIfExists('payment_allocations');
        Schema::dropIfExists('payments');
    }
};
