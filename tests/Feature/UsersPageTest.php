<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class UsersPageTest extends TestCase
{
    use RefreshDatabase;

    protected function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'name' => 'Ada Admin']);
    }

    public function test_only_admins_can_see_the_users_page(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'management']))->get('/users')->assertForbidden();

        $supervisor = User::factory()->create(['role' => 'supervisor']);
        User::factory()->create(['role' => 'facility_user']);

        $this->actingAs($this->admin())->get('/users')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('App/Users')
            ->has('roles', 4)
            ->has('users', 3) // management + supervisor + admin, not the facility user
            ->where('users', fn ($users) => collect($users)->firstWhere('id', $supervisor->id)['can_impersonate'] === true));
    }

    public function test_admin_can_add_a_user(): void
    {
        $this->actingAs($this->admin())->post('/users', [
            'name' => 'New Accountant', 'email' => 'new@example.com', 'role' => 'accountant', 'password' => 'secret-pass-123',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'new@example.com', 'role' => 'accountant']);
    }

    public function test_admin_can_impersonate_and_return(): void
    {
        $admin = $this->admin();
        $accountant = User::factory()->create(['role' => 'accountant']);

        $this->actingAs($admin)->post("/users/{$accountant->id}/impersonate")->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($accountant);

        $this->get('/payments')->assertInertia(fn (Assert $page) => $page->where('auth.impersonator', 'Ada Admin'));
        $this->get('/users')->assertForbidden();

        $this->post('/impersonate/leave')->assertRedirect(route('users'));
        $this->assertAuthenticatedAs($admin);
        $this->get('/users')->assertInertia(fn (Assert $page) => $page->where('auth.impersonator', null));
    }

    public function test_supervisors_land_on_facility_users(): void
    {
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $this->actingAs($this->admin())->post("/users/{$supervisor->id}/impersonate")->assertRedirect(route('facility-users'));
    }

    public function test_cannot_impersonate_admins_inactive_users_or_facility_users(): void
    {
        $admin = $this->admin();

        foreach ([
            User::factory()->create(['role' => 'admin']),
            User::factory()->create(['role' => 'supervisor', 'is_active' => false]),
            User::factory()->create(['role' => 'facility_user']),
            $admin,
        ] as $target) {
            $this->actingAs($admin)->post("/users/{$target->id}/impersonate");
            $this->assertAuthenticatedAs($admin);
        }
    }

    public function test_leaving_without_impersonating_is_forbidden(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'accountant']))->post('/impersonate/leave')->assertForbidden();
    }
}
