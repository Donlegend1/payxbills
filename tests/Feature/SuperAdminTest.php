<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Company;
use App\Models\Occupancy;
use App\Models\User;
use App\Services\BillingService;
use App\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\CreatesFacilityUsers;
use Tests\TestCase;

/**
 * The super admin (Fivestar Technologies) oversees every company: payments, facilities,
 * facility users, categories and everything else.
 */
class SuperAdminTest extends TestCase
{
    use CreatesFacilityUsers, RefreshDatabase;

    protected Company $other;

    protected Occupancy $mine;

    protected Occupancy $theirs;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-03-10 09:00');
        Notification::fake();

        $billing = app(BillingService::class);
        $this->mine = $this->occupancy(category: $this->category(15000));
        $billing->confirmPayment($billing->startOnlinePayment($this->mine, 1));

        $this->other = Company::create(['name' => 'Sunrise Estates']);
        $this->theirs = CurrentCompany::run($this->other, function () use ($billing) {
            $theirs = $this->occupancy(category: $this->category(40000));
            $billing->generateMonthly();

            return $theirs;
        });
    }

    public function test_overview_shows_every_company_and_its_money(): void
    {
        $this->actingAs($this->superAdmin())->get('/platform/companies')->assertInertia(fn (Assert $page) => $page
            ->component('Platform/Companies')
            ->has('companies', 2)
            ->where('totals.facility_users', 2)
            ->where('totals.billed', 40000) // the payment in setUp paid for January, so only Sunrise billed March
            ->where('totals.collected', 15000)
            ->where('companies.0.name', 'Sunrise Estates')
            ->where('companies.0.billed', 40000)
            ->where('companies.1.collected', 15000));
    }

    public function test_lists_show_records_of_all_companies_and_filter_by_company(): void
    {
        $this->actingAs($this->superAdmin());

        $this->get('/platform/facility-users')->assertInertia(fn (Assert $page) => $page->component('Platform/Records')->has('rows.data', 2)->has('companies', 2));
        $this->get('/platform/facility-users?company='.$this->other->id)->assertInertia(fn (Assert $page) => $page
            ->has('rows.data', 1)->where('rows.data.0.company', 'Sunrise Estates'));

        $this->get('/platform/payments')->assertInertia(fn (Assert $page) => $page->has('rows.data', 1)->where('rows.data.0.company', 'Test Company'));
        $this->get('/platform/invoices')->assertInertia(fn (Assert $page) => $page->has('rows.data', 2));
        $this->get('/platform/facilities')->assertInertia(fn (Assert $page) => $page->has('rows.data', 2));
        $this->get('/platform/categories?q=Standard')->assertInertia(fn (Assert $page) => $page->has('rows.data', 2));
    }

    public function test_super_admin_opens_a_company_and_manages_everything_in_it(): void
    {
        $super = $this->superAdmin();
        $this->actingAs($super)->get('/facilities')->assertRedirect(route('platform.companies'), 'no company open yet');

        $this->post("/platform/companies/{$this->other->id}/open")->assertRedirect(route('dashboard'));

        $this->get('/dashboard')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->where('currentCompany.name', 'Sunrise Estates')
            ->where('auth.managing.name', 'Sunrise Estates')
            ->where('auth.can', fn ($can) => collect($can)->contains('clear-payments') && collect($can)->contains('manage-settings') && ! collect($can)->contains('use-portal')));

        // Only that company's data
        $this->get('/facility-users')->assertInertia(fn (Assert $page) => $page->has('occupancies.data', 1)->where('occupancies.data.0.id', $this->theirs->id));
        $this->get("/facilities/{$this->mine->facility_id}")->assertNotFound();

        // Full rights: change a category's price, register and activate a facility user
        $category = $this->theirs->category;
        $this->put("/categories/{$category->id}", ['name' => $category->name, 'monthly_amount' => 45000, 'is_active' => true])->assertSessionHasNoErrors();
        $this->assertEquals(45000, $category->fresh()->monthly_amount);

        $this->post('/facility-users', ['name' => 'New Resident', 'category_id' => $category->id])->assertSessionHasNoErrors();
        $new = Occupancy::acrossCompanies()->latest('id')->first();
        $this->assertSame('New Resident', User::acrossCompanies()->find($new->user_id)->name);
        $this->assertSame($this->other->id, $new->company_id, 'created in the open company');
        $this->post("/facility-users/{$new->id}/activate")->assertSessionHasNoErrors();
        $this->assertTrue($new->fresh()->billing_active);

        // Back to all companies
        $this->post('/platform/close')->assertRedirect(route('platform.companies'));
        $this->get('/facility-users')->assertRedirect(route('platform.companies'));
    }

    public function test_the_platform_pages_ignore_the_open_company(): void
    {
        $this->actingAs($this->superAdmin())->post("/platform/companies/{$this->other->id}/open");

        $this->get('/platform/facility-users')->assertInertia(fn (Assert $page) => $page->has('rows.data', 2)->where('auth.managing.name', 'Sunrise Estates'));
    }

    public function test_company_staff_cannot_reach_the_super_admin_area(): void
    {
        foreach (['admin', 'accountant'] as $role) {
            $this->actingAs($this->staff($role));
            foreach (['/platform/companies', '/platform/payments', '/platform/facility-users', '/platform/categories'] as $url) {
                $this->get($url)->assertForbidden();
            }
            $this->post("/platform/companies/{$this->other->id}/open")->assertForbidden();
        }

        // Even with the session key set by hand, a company admin stays in their own company
        $admin = $this->staff('admin');
        $this->actingAs($admin)->withSession(['platform_company_id' => $this->other->id])->get('/facility-users')
            ->assertInertia(fn (Assert $page) => $page->where('currentCompany.name', 'Test Company')->has('occupancies.data', 1)->where('occupancies.data.0.id', $this->mine->id));
    }

    protected function superAdmin(): User
    {
        $user = User::factory()->create(['name' => 'Super Admin', 'role' => 'admin']);
        $user->forceFill(['company_id' => null, 'is_platform_admin' => true])->save();
        CurrentCompany::set(null);

        return $user->fresh();
    }
}
