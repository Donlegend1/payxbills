<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\CreatesFacilityUsers;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use CreatesFacilityUsers, RefreshDatabase;

    public function test_visitor_pages_are_public(): void
    {
        foreach (['/', '/features', '/about', '/contact', '/register'] as $url) {
            $this->get($url)->assertOk();
        }

        $this->get('/services')->assertRedirect('/features');
    }

    public function test_app_pages_require_login(): void
    {
        foreach (['/dashboard', '/facility-users', '/portal'] as $url) {
            $this->get($url)->assertRedirect('/login');
        }
    }

    public function test_office_staff_can_open_every_dashboard_page(): void
    {
        $occupancy = $this->occupancy();

        foreach (['admin', 'management', 'accountant'] as $role) {
            $user = User::factory()->create(['role' => $role]);

            foreach (['/dashboard', '/facility-users', '/facilities', "/facilities/{$occupancy->facility_id}", '/invoices', '/payments', '/receivables', '/expenditure', '/reports', '/analytics', '/notifications'] as $url) {
                $this->actingAs($user)->get($url)->assertOk();
            }
        }
    }

    public function test_only_the_super_admin_manages_settings_facilities_and_categories(): void
    {
        $management = $this->staff('management');
        $this->actingAs($management)->get('/settings')->assertForbidden();
        $this->actingAs($management)->get('/categories')->assertForbidden();
        $this->actingAs($management)->post('/facilities', ['name' => 'X'])->assertForbidden();

        $admin = $this->staff('admin');
        $this->actingAs($admin)->get('/settings')->assertOk();
        $this->actingAs($admin)->get('/categories')->assertOk();
        $this->actingAs($admin)->post('/categories', ['name' => 'Shop', 'monthly_amount' => 40000])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/facilities', ['name' => 'Lakeview Lodge'])->assertSessionHasNoErrors();
    }

    public function test_super_admin_adds_a_numbered_range_of_spaces(): void
    {
        $facility = $this->facility(spaces: 1); // has room 101

        $this->actingAs($this->staff('admin'))
            ->post("/facilities/{$facility->id}/spaces", ['label' => 'Room', 'from' => 100, 'to' => 105])
            ->assertSessionHas('success', '5 space(s) added. 1 already existed and were skipped.');

        $this->assertSame(6, $facility->spaces()->count());
    }

    public function test_supervisor_lands_on_facility_users(): void
    {
        $this->actingAs($this->staff('supervisor'))->get('/dashboard')->assertRedirect(route('facility-users'));
    }

    public function test_management_cannot_record_payments(): void
    {
        $occupancy = $this->occupancy();

        $this->actingAs($this->staff('management'))
            ->post('/payments', ['occupancy_id' => $occupancy->id, 'amount' => 1000, 'method' => 'cash', 'paid_on' => today()->toDateString()])
            ->assertForbidden();
    }

    public function test_contact_enquiry_is_accepted(): void
    {
        $this->post('/contact', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'message' => 'Please quote for estate cleaning.',
        ])->assertSessionHasNoErrors()->assertRedirect('/contact');
    }
}
