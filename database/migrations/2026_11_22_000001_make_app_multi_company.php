<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * PayXbills serves many companies from one database. Every business table gets a company_id,
 * and settings become per company.
 *
 * Safe on a database that already holds data: everything already there is moved into one
 * company named after the existing company_name setting (Cosmopolitan Cleaners Limited).
 */
return new class extends Migration
{
    /** Tables whose rows belong to one company. */
    protected const TENANT_TABLES = [
        'users', 'facilities', 'spaces', 'categories', 'occupancies', 'removal_requests', 'invoices',
        'payments', 'payment_allocations', 'payment_attachments', 'expenses', 'message_logs', 'sms_logs',
    ];

    /** Settings rows from before the upgrade, until we know which company they belong to. */
    protected Collection $oldSettings;

    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('email')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('address')->nullable();
            // Suspended companies cannot log in; their pay links stop working
            $table->boolean('is_active')->default(true);
            // The company's own Paystack account, so money goes straight to them (encrypted)
            $table->text('paystack_secret_key')->nullable();
            $table->timestamps();
        });

        $sqlite = DB::getDriverName() === 'sqlite';

        foreach (self::TENANT_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($sqlite) {
                $column = $table->foreignId('company_id')->nullable()->after('id');
                // SQLite cannot add a foreign key to an existing table
                $sqlite ? $table->index('company_id') : $column->constrained()->cascadeOnDelete();
            });
        }

        // Fivestar Technologies staff who run the platform; they belong to no company
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false)->after('role');
        });

        // Category names only need to be unique inside one company
        Schema::table('categories', function (Blueprint $table) {
            $table->dropUnique(['name']);
            $table->unique(['company_id', 'name']);
        });

        $this->rebuildSettings();
        $this->adoptExistingData();
    }

    public function down(): void
    {
        $sqlite = DB::getDriverName() === 'sqlite';

        // MySQL uses the (company_id, name) index for the foreign key, so drop the key first
        Schema::table('categories', function (Blueprint $table) use ($sqlite) {
            if (! $sqlite) {
                $table->dropForeign(['company_id']);
            }
            $table->dropUnique(['company_id', 'name']);
            $table->unique('name');
        });

        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_platform_admin'));

        foreach (self::TENANT_TABLES as $name) {
            Schema::table($name, function (Blueprint $table) use ($sqlite, $name) {
                if ($sqlite) {
                    $table->dropIndex(['company_id']);
                } elseif ($name !== 'categories') {
                    $table->dropForeign(['company_id']);
                }
                $table->dropColumn('company_id');
            });
        }

        Schema::drop('settings');
        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });

        Schema::dropIfExists('companies');
    }

    /**
     * settings was keyed by "key" alone; it is now keyed by company and key.
     */
    protected function rebuildSettings(): void
    {
        $old = DB::table('settings')->get();

        Schema::drop('settings');
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnDelete();
            $table->string('key');
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['company_id', 'key']);
        });

        $this->oldSettings = $old;
    }

    /**
     * Put every existing row into one company, so an upgraded install keeps working.
     */
    protected function adoptExistingData(): void
    {
        $hasData = $this->oldSettings->isNotEmpty() || DB::table('users')->exists() || DB::table('facilities')->exists();

        if (! $hasData) {
            return;
        }

        $name = $this->oldSettings->firstWhere('key', 'company_name')?->value ?: 'Cosmopolitan Cleaners Limited';

        $companyId = DB::table('companies')->insertGetId([
            'name' => $name,
            'slug' => Str::slug($name),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (self::TENANT_TABLES as $table) {
            DB::table($table)->whereNull('company_id')->update(['company_id' => $companyId]);
        }

        foreach ($this->oldSettings as $setting) {
            // The company name now lives on the company itself
            if ($setting->key === 'company_name') {
                continue;
            }

            DB::table('settings')->insert([
                'company_id' => $companyId,
                'key' => $setting->key,
                'value' => $setting->value,
                'created_at' => $setting->created_at,
                'updated_at' => $setting->updated_at,
            ]);
        }
    }
};
