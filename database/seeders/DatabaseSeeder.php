<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Setting;
use App\Models\User;
use App\Tenancy\CurrentCompany;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * A PayXbills platform admin, and Cosmopolitan Cleaners as the first client company with
     * staff logins for every role, plus demo billing data outside production.
     */
    public function run(): void
    {
        $this->platformAdmin();

        $company = Company::firstOrCreate(['slug' => 'cosmopolitan-cleaners-limited'], [
            'name' => 'Cosmopolitan Cleaners Limited',
            'email' => 'info@cosmocleaners.ng',
            'phone' => '+234 800 000 0000',
        ]);

        CurrentCompany::run($company, function () {
            Setting::put(['sms_sender' => 'COSMOCLEAN']);
            $this->call(RoleUserSeeder::class);

            if (! app()->isProduction()) {
                $this->call(DemoDataSeeder::class);
            }
        });
    }

    /**
     * Fivestar Technologies' super admin: oversees every company (belongs to none).
     * Set PLATFORM_ADMIN_PASSWORD before seeding a real server.
     */
    protected function platformAdmin(): void
    {
        $user = User::acrossCompanies()->firstOrNew(['email' => 'platform@payxbills.com']);

        $user->forceFill([
            'name' => 'PayXbills Super Admin',
            'role' => Role::Admin,
            'company_id' => null,
            'is_platform_admin' => true,
            'is_active' => true,
            'password' => env('PLATFORM_ADMIN_PASSWORD', 'password'),
            'email_verified_at' => now(),
        ])->save();
    }
}
