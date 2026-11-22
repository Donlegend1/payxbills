<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every SMS the app sends (or tries to), one row per recipient
        Schema::create('sms_logs', function (Blueprint $table) {
            $table->id();
            $table->string('recipient', 32);
            $table->string('sender_id')->nullable();
            $table->text('message');
            $table->string('driver', 32);
            $table->enum('status', ['queued', 'sent', 'delivered', 'failed'])->default('queued');
            $table->string('provider_message_id')->nullable()->index();
            $table->string('provider_status')->nullable();
            $table->unsignedSmallInteger('provider_status_code')->nullable();
            $table->string('cost')->nullable();
            $table->text('error')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->nullableMorphs('related');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('recipient');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_logs');
    }
};
