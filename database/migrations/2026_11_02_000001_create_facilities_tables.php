<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Facilities are created by the super admin; each is looked after by one supervisor
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->foreignId('supervisor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Rooms, shops, stalls... inside a facility, each with a number unique to that facility
        Schema::create('spaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('facility_id')->constrained()->cascadeOnDelete();
            $table->string('number', 30);
            $table->string('label')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['facility_id', 'number']);
        });

        // What a facility user pays each month
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->decimal('monthly_amount', 14, 2);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // A facility user's place in a facility: one space, one category. One active occupancy per space.
        Schema::create('occupancies', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable()->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('facility_id')->constrained()->restrictOnDelete();
            $table->foreignId('space_id')->constrained()->restrictOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->enum('status', ['active', 'ended'])->default('active');
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['space_id', 'status']);
            $table->index(['facility_id', 'status']);
        });

        // A supervisor asks to remove a facility user; the super admin approves or rejects
        Schema::create('removal_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('occupancy_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason');
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note')->nullable();
            $table->timestamps();

            $table->index('status');
        });

        // One invoice per occupancy per month
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('number')->nullable()->unique();
            $table->foreignId('occupancy_id')->constrained()->cascadeOnDelete();
            $table->char('period', 7); // YYYY-MM
            $table->string('description');
            $table->decimal('amount', 14, 2);
            $table->decimal('amount_paid', 14, 2)->default(0);
            $table->date('issue_date');
            $table->date('due_date');
            // "Overdue" is not stored: it is an open invoice past its due date plus grace days
            $table->enum('status', ['unpaid', 'partial', 'paid', 'cancelled'])->default('unpaid');
            $table->timestamp('sent_at')->nullable();
            $table->boolean('nrs_copy')->default(false);
            $table->date('paid_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['occupancy_id', 'period']);
            $table->index(['status', 'due_date']);
            $table->index('period');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('removal_requests');
        Schema::dropIfExists('occupancies');
        Schema::dropIfExists('categories');
        Schema::dropIfExists('spaces');
        Schema::dropIfExists('facilities');
    }
};
