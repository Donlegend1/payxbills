<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * One login per role for testing. Every account uses the password "password".
 */
class RoleUserSeeder extends Seeder
{
    public const USERS = [
        ['name' => 'System Administrator', 'email' => 'admin@cosmocleaners.ng', 'role' => Role::Admin],
        ['name' => 'Musa Abdullahi', 'email' => 'management@cosmocleaners.ng', 'role' => Role::Management],
        ['name' => 'Chioma Okeke', 'email' => 'accounts@cosmocleaners.ng', 'role' => Role::Accountant],
        ['name' => 'Musa Ibrahim', 'email' => 'supervisor@cosmocleaners.ng', 'role' => Role::Supervisor],
    ];

    public function run(): void
    {
        foreach (self::USERS as $user) {
            User::updateOrCreate(
                ['email' => $user['email']],
                [...$user, 'password' => 'password', 'email_verified_at' => now()],
            );
        }
    }
}
