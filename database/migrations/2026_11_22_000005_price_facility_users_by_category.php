<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One list of categories (House, School, Shop, Standard room...), each with its monthly amount.
 * A facility user pays their category's amount, so changing the category's amount reprices everyone
 * in it from their next bill.
 *
 * Replaces "category of facility" (facility_types, no price) and per-facility-user amounts. Nobody's
 * price changes: each facility type becomes a category, and a facility user whose own amount differs
 * from their category's is moved to a category with that amount.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        $category = function (int $companyId, string $name, float $amount) use ($now): int {
            $existing = DB::table('categories')->where('company_id', $companyId)->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();

            return $existing?->id ?? DB::table('categories')->insertGetId([
                'company_id' => $companyId, 'name' => $name, 'monthly_amount' => $amount, 'is_active' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        };

        // Each facility type becomes a category, priced at what most of its facility users pay
        foreach (DB::table('facility_types')->get() as $type) {
            $amount = (float) (DB::table('occupancies')->where('facility_type_id', $type->id)->whereNotNull('amount')
                ->selectRaw('amount, count(*) as n')->groupBy('amount')->orderByDesc('n')->value('amount') ?? 0);

            $id = $category($type->company_id, $type->name, $amount);

            DB::table('occupancies')->where('facility_type_id', $type->id)->whereNull('category_id')->update(['category_id' => $id]);
        }

        // Own amounts: keep the price by using (or adding) a category with that amount
        $custom = DB::table('occupancies')
            ->leftJoin('categories', 'categories.id', '=', 'occupancies.category_id')
            ->whereNotNull('occupancies.amount')
            ->select('occupancies.id', 'occupancies.company_id', 'occupancies.amount', 'categories.name', 'categories.monthly_amount')
            ->get();

        foreach ($custom as $o) {
            if ($o->name !== null && (float) $o->monthly_amount === (float) $o->amount) {
                continue;
            }

            $label = ($o->name ?? 'Custom').' – ₦'.number_format((float) $o->amount, floor((float) $o->amount) == (float) $o->amount ? 0 : 2);
            DB::table('occupancies')->where('id', $o->id)->update(['category_id' => $category($o->company_id, $label, (float) $o->amount)]);
        }

        Schema::table('occupancies', function (Blueprint $table) {
            $table->dropConstrainedForeignId('facility_type_id');
            $table->dropColumn('amount');
        });

        Schema::dropIfExists('facility_types');
    }

    public function down(): void
    {
        Schema::create('facility_types', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['company_id', 'name']);
        });

        Schema::table('occupancies', function (Blueprint $table) {
            $table->foreignId('facility_type_id')->nullable()->after('district')->constrained()->nullOnDelete();
            $table->decimal('amount', 14, 2)->nullable()->after('frequency');
        });
    }
};
