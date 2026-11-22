<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Facility;
use App\Models\Invoice;
use App\Models\Occupancy;
use App\Models\Setting;
use App\Models\User;
use App\Services\PaystackService;
use App\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use LogicException;
use Tests\CreatesFacilityUsers;
use Tests\TestCase;

/**
 * PayXbills serves many companies from one database. Each company must only ever see its own data.
 */
class MultiCompanyTest extends TestCase
{
    use CreatesFacilityUsers, RefreshDatabase;

    protected Company $other;

    protected Facility $otherFacility;

    protected Occupancy $otherOccupancy;

    protected int $otherVacantSpaceId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-10 09:00');
        Notification::fake();

        // A second company with its own facility and facility user
        $this->other = Company::create(['name' => 'Sunrise Estates']);
        CurrentCompany::run($this->other, function () {
            $this->otherFacility = $this->facility();
            $this->otherFacility->update(['name' => 'Sunrise Court']);
            $this->otherOccupancy = $this->occupancy($this->otherFacility, user: ['email' => 'tenant@sunrise.example.com']);
            $this->otherVacantSpaceId = $this->otherFacility->spaces()->vacant()->value('id');
        });
    }

    public function test_staff_only_see_their_own_company(): void
    {
        $admin = $this->staff('admin');
        $mine = $this->facility();

        $this->actingAs($admin)->get('/facilities')->assertInertia(fn (Assert $page) => $page
            ->has('facilities', 1)
            ->where('facilities.0.id', $mine->id)
            ->where('currentCompany.name', 'Test Company'));

        $this->get('/facility-users')->assertInertia(fn (Assert $page) => $page->has('occupancies.data', 0));
    }

    public function test_another_companys_records_cannot_be_opened_or_changed(): void
    {
        $admin = $this->staff('admin');

        $this->actingAs($admin)->get("/facilities/{$this->otherFacility->id}")->assertNotFound();
        $this->put("/facilities/{$this->otherFacility->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->post("/facility-users/{$this->otherOccupancy->id}/remind")->assertNotFound();

        $this->assertSame('Sunrise Court', $this->otherFacility->fresh()->name);
    }

    public function test_another_companys_ids_are_rejected_in_forms(): void
    {
        $admin = $this->staff('admin');
        $category = $this->category();

        $this->actingAs($admin)->post('/facility-users', [
            'name' => 'Ada', 'email' => 'ada@example.com', 'space_id' => $this->otherVacantSpaceId,
            'category_id' => $category->id, 'start_date' => '2026-03-01',
        ])->assertSessionHasErrors('space_id');

        $this->post('/expenditure', [
            'spent_on' => '2026-03-01', 'description' => 'Fuel', 'category' => 'Supplies', 'facility_id' => $this->otherFacility->id, 'amount' => 5000,
        ])->assertSessionHasErrors('facility_id');

        $this->post('/payments', [
            'occupancy_id' => $this->otherOccupancy->id, 'amount' => 1000, 'method' => 'cash', 'paid_on' => '2026-03-01',
        ])->assertSessionHasErrors('occupancy_id');
    }

    public function test_an_email_used_by_another_company_cannot_be_registered_again(): void
    {
        $admin = $this->staff('admin');
        $facility = $this->facility();
        $category = $this->category();

        $this->actingAs($admin)->post('/facility-users', [
            'name' => 'Copy', 'email' => 'tenant@sunrise.example.com', 'space_id' => $facility->spaces()->first()->id,
            'category_id' => $category->id, 'start_date' => '2026-03-01',
        ])->assertSessionHasErrors(['email' => 'This email is already registered with another organisation on PayXbills. Use a different email.']);
    }

    public function test_a_company_registers_and_gets_its_own_workspace(): void
    {
        $this->post('/register', [
            'company_name' => 'Lakeside Hostels Ltd',
            'company_phone' => '08012345678',
            'name' => 'Bola Ade',
            'email' => 'bola@lakeside.example.com',
            'password' => 'secret-pass-123',
            'password_confirmation' => 'secret-pass-123',
        ])->assertRedirect(route('facilities'));

        $company = Company::where('name', 'Lakeside Hostels Ltd')->sole();
        $admin = User::acrossCompanies()->where('email', 'bola@lakeside.example.com')->sole();

        $this->assertAuthenticatedAs($admin);
        $this->assertSame($company->id, $admin->company_id);
        $this->assertSame('admin', $admin->role->value);
        $this->assertSame('lakeside-hostels-ltd', $company->slug);

        // The new company starts empty and has its own settings
        $this->get('/facilities')->assertInertia(fn (Assert $page) => $page->has('facilities', 0)->where('currentCompany.name', 'Lakeside Hostels Ltd'));
        CurrentCompany::run($company, fn () => $this->assertSame('LAKESIDEHOS', Setting::get('sms_sender')));
    }

    public function test_settings_belong_to_one_company(): void
    {
        Setting::put(['billing_day' => 5, 'company_name' => 'Renamed Co']);

        $this->assertSame('Renamed Co', $this->company->fresh()->name);
        CurrentCompany::run($this->other, function () {
            $this->assertSame(1, Setting::get('billing_day'));
            $this->assertSame('Sunrise Estates', Setting::get('company_name'));
        });
        $this->assertSame(5, Setting::get('billing_day'));
    }

    public function test_monthly_billing_runs_for_each_company_with_its_own_settings(): void
    {
        $mine = $this->occupancy();
        Setting::put(['invoice_prefix' => 'TST']);
        CurrentCompany::run($this->other, fn () => Setting::put(['invoice_prefix' => 'SUN']));
        CurrentCompany::set(null);

        $this->artisan('billing:generate-invoices')->assertSuccessful();

        $this->assertStringStartsWith('TST-', Invoice::acrossCompanies()->where('occupancy_id', $mine->id)->sole()->number);
        $this->assertStringStartsWith('SUN-', Invoice::acrossCompanies()->where('occupancy_id', $this->otherOccupancy->id)->sole()->number);
        $this->assertSame($this->other->id, Invoice::acrossCompanies()->where('occupancy_id', $this->otherOccupancy->id)->sole()->company_id);
    }

    public function test_signed_pay_links_work_without_login_and_stop_when_the_company_is_suspended(): void
    {
        $url = $this->otherOccupancy->payUrl();
        CurrentCompany::set(null);

        $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->where('company', 'Sunrise Estates'));

        $this->other->update(['is_active' => false]);
        $this->get($url)->assertNotFound();
    }

    public function test_records_cannot_be_created_without_a_company(): void
    {
        CurrentCompany::set(null);

        $this->expectException(LogicException::class);
        Facility::create(['name' => 'Nowhere']);
    }

    public function test_each_company_can_use_its_own_paystack_account(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_platform']);
        $this->other->update(['paystack_secret_key' => 'sk_test_sunrise']);

        $this->assertSame('sk_test_platform', app(PaystackService::class)->secret());
        CurrentCompany::run($this->other->fresh(), fn () => $this->assertSame('sk_test_sunrise', app(PaystackService::class)->secret()));
        $this->assertNotSame('sk_test_sunrise', $this->other->fresh()->getRawOriginal('paystack_secret_key'), 'stored encrypted');
    }

    public function test_platform_admin_manages_companies_but_not_their_data(): void
    {
        $companyAdmin = $this->staff('admin');
        $platform = $this->platformAdmin();

        $this->actingAs($platform)->get('/dashboard')->assertRedirect(route('platform.companies'));
        $this->get('/facilities')->assertRedirect(route('platform.companies'));

        $this->get('/platform/companies')->assertInertia(fn (Assert $page) => $page
            ->component('Platform/Companies')
            ->has('companies', 2)
            ->where('auth.can', ['manage-companies']));

        $this->actingAs($companyAdmin)->get('/platform/companies')->assertForbidden();
    }

    public function test_suspending_a_company_logs_its_users_out(): void
    {
        $admin = $this->staff('admin');
        $this->actingAs($this->platformAdmin())->put("/platform/companies/{$this->company->id}", ['is_active' => false])->assertSessionHasNoErrors();

        $this->assertFalse($this->company->fresh()->is_active);
        $this->actingAs($admin)->get('/facilities')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_platform_admin_can_log_in_as_a_company_admin_and_return(): void
    {
        $platform = $this->platformAdmin();
        $admin = CurrentCompany::run($this->other, fn () => $this->staff('admin'));

        $this->actingAs($platform)->post("/platform/companies/{$this->other->id}/impersonate")->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($admin);

        $this->get('/facilities')->assertInertia(fn (Assert $page) => $page
            ->has('facilities', 1)
            ->where('facilities.0.name', 'Sunrise Court')
            ->where('auth.impersonator', 'Platform Admin'));

        $this->post('/impersonate/leave')->assertRedirect(route('platform.companies'));
        $this->assertAuthenticatedAs($platform);
    }

    protected function platformAdmin(): User
    {
        $user = CurrentCompany::run($this->company, fn () => User::factory()->create(['name' => 'Platform Admin', 'role' => 'admin']));
        $user->forceFill(['company_id' => null, 'is_platform_admin' => true])->save();
        CurrentCompany::set(null);

        return $user->fresh();
    }
}
