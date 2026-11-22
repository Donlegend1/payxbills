<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * - Billing starts only when an admin activates a facility user, and stops when they are deactivated.
 * - Edits to a facility user made by staff other than the admin wait for the admin's approval.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('occupancies', function (Blueprint $table) {
            // New facility users are not billed until activated
            $table->boolean('billing_active')->default(false)->after('status');
            $table->timestamp('activated_at')->nullable()->after('billing_active');
            $table->timestamp('deactivated_at')->nullable()->after('activated_at');
        });

        // Everyone already on the system was being billed
        DB::table('occupancies')->where('status', 'active')->update(['billing_active' => true, 'activated_at' => DB::raw('created_at')]);

        Schema::create('client_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('occupancy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            // The new values asked for, keyed by field
            $table->json('changes');
            $table->string('status', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();

            $table->index(['occupancy_id', 'status']);
        });

        // New message type "activation" (welcome with the pay link, no password)
        Schema::table('message_logs', function (Blueprint $table) {
            $table->string('type', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_change_requests');

        Schema::table('occupancies', function (Blueprint $table) {
            $table->dropColumn(['billing_active', 'activated_at', 'deactivated_at']);
        });
    }
};
