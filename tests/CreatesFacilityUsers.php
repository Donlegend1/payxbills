<?php

namespace Tests;

use App\Models\Category;
use App\Models\Facility;
use App\Models\Occupancy;
use App\Models\Space;
use App\Models\User;

/**
 * Builds facilities, spaces, categories and facility users for tests.
 */
trait CreatesFacilityUsers
{
    protected function facility(?User $supervisor = null, int $spaces = 3): Facility
    {
        $facility = Facility::create([
            'name' => 'Green Valley Hostel',
            'address' => 'Plot 12, Lekki',
            'phone' => '+2348000000001',
            'email' => 'office@greenvalley.example.com',
            'supervisor_id' => $supervisor?->id,
        ]);

        foreach (range(1, $spaces) as $n) {
            $facility->spaces()->create(['number' => (string) (100 + $n), 'label' => 'Room']);
        }

        return $facility;
    }

    protected function category(float $amount = 15000): Category
    {
        return Category::create(['name' => 'Standard room '.(Category::count() + 1), 'monthly_amount' => $amount]);
    }

    protected function occupancy(?Facility $facility = null, ?Category $category = null, string $start = '2026-01-01', array $user = []): Occupancy
    {
        $facility ??= $this->facility();
        $category ??= $this->category();
        $space = Space::where('facility_id', $facility->id)->vacant()->firstOrFail();

        $account = User::factory()->create(['role' => 'facility_user', 'phone' => '+2348011111111', ...$user]);

        return Occupancy::create([
            'user_id' => $account->id,
            'facility_id' => $facility->id,
            'space_id' => $space->id,
            'category_id' => $category->id,
            'start_date' => $start,
            // Already activated, like everyone billed today
            'billing_active' => true,
            'activated_at' => $start,
        ]);
    }

    protected function staff(string $role): User
    {
        return User::factory()->create(['role' => $role]);
    }
}
