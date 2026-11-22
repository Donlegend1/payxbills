<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Revenue collection for service companies (e.g. waste collection):
 *  - clients (facility users) get an address, a type of facility, bins, collection frequency,
 *    their own monthly amount and billing day; every detail is optional, including the facility,
 *    space, billing category and email, so clients can be imported from incomplete spreadsheets;
 *  - expense memos can be partially approved or kept in view (KIV), carry a picture of the memo,
 *    and split across several categories, each approved separately.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';

        // Lists each company builds up: House, School, Shop... / Wheeler bin, Mammoth bin...
        foreach (['facility_types', 'bin_types'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->foreignId('company_id')->constrained()->cascadeOnDelete();
                $table->string('name', 100);
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->unique(['company_id', 'name']);
            });
        }

        Schema::table('occupancies', function (Blueprint $table) {
            $table->string('house_no', 50)->nullable()->after('category_id');
            $table->string('street')->nullable()->after('house_no');
            $table->string('district', 100)->nullable()->after('street');
            $table->foreignId('facility_type_id')->nullable()->after('district')->constrained()->nullOnDelete();
            $table->foreignId('bin_type_id')->nullable()->after('facility_type_id')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('bins')->nullable()->after('bin_type_id');
            // Collections per week
            $table->unsignedTinyInteger('frequency')->nullable()->after('bins');
            // Monthly amount for this client; when empty the billing category's amount is used
            $table->decimal('amount', 14, 2)->nullable()->after('frequency');
            // Day of the month this client is billed (1-28); when empty the company's billing day is used
            $table->unsignedTinyInteger('billing_day')->nullable()->after('amount');
        });

        // A client need not be in a facility, space or billing category
        Schema::table('occupancies', function (Blueprint $table) {
            $table->unsignedBigInteger('facility_id')->nullable()->change();
            $table->unsignedBigInteger('space_id')->nullable()->change();
            $table->unsignedBigInteger('category_id')->nullable()->change();
        });

        // Clients may have no email (they then get SMS only and cannot log in)
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
        });

        Schema::table('expenses', function (Blueprint $table) {
            // pending, approved, partially_approved, kiv, rejected
            $table->string('status', 20)->default('pending')->change();
            $table->decimal('approved_amount', 14, 2)->nullable()->after('amount');
            $table->string('memo_path')->nullable()->after('approved_amount');
            $table->string('memo_name')->nullable()->after('memo_path');
            $table->string('review_note')->nullable()->after('reviewed_at');
        });

        // One row per category on a memo. A single-category memo has one row.
        Schema::create('expense_lines', function (Blueprint $table) use ($sqlite) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->foreignId('expense_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->decimal('amount', 14, 2);
            // Set when approved; null for categories not approved
            $table->decimal('approved_amount', 14, 2)->nullable();
            $table->timestamps();

            $table->index(['expense_id']);
        });

        // Existing memos become single-category memos
        DB::table('expenses')->orderBy('id')->each(function ($e) {
            $approved = $e->status === 'approved' ? $e->amount : null;

            DB::table('expense_lines')->insert([
                'company_id' => $e->company_id,
                'expense_id' => $e->id,
                'category' => $e->category,
                'amount' => $e->amount,
                'approved_amount' => $approved,
                'created_at' => $e->created_at,
                'updated_at' => $e->updated_at,
            ]);

            DB::table('expenses')->where('id', $e->id)->update(['approved_amount' => $approved]);
        });

        // Account codes and memo numbers that were never set (they were silently dropped on create)
        DB::table('occupancies')->whereNull('code')->pluck('id')
            ->each(fn ($id) => DB::table('occupancies')->where('id', $id)->update(['code' => 'FU-'.(1000 + $id)]));
        DB::table('expenses')->whereNull('memo_number')->pluck('id')
            ->each(fn ($id) => DB::table('expenses')->where('id', $id)->update(['memo_number' => sprintf('MEM-%04d', $id)]));
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_lines');

        Schema::table('expenses', function (Blueprint $table) {
            $table->dropColumn(['approved_amount', 'memo_path', 'memo_name', 'review_note']);
        });

        Schema::table('occupancies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('facility_type_id');
            $table->dropConstrainedForeignId('bin_type_id');
            $table->dropColumn(['house_no', 'street', 'district', 'bins', 'frequency', 'amount', 'billing_day']);
        });

        Schema::dropIfExists('bin_types');
        Schema::dropIfExists('facility_types');
    }
};
