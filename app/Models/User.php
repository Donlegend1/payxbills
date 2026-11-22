<?php

namespace App\Models;

use App\Enums\Role;
use App\Tenancy\BelongsToCompany;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Staff (company admin, management, accountant, supervisor) and facility users all log in as users,
 * and belong to one company. Platform admins (Fivestar Technologies staff running PayXbills) belong to none.
 *
 * Emails are unique across all companies, so one login form serves everyone.
 */
#[Fillable(['name', 'email', 'phone', 'password', 'role', 'is_active', 'must_change_password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToCompany, HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => Role::class,
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'is_platform_admin' => 'boolean',
        ];
    }

    public function requiresCompany(): bool
    {
        return false;
    }

    public function isPlatformAdmin(): bool
    {
        return (bool) $this->is_platform_admin;
    }

    /**
     * Facilities this user looks after as a supervisor.
     */
    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class, 'supervisor_id');
    }

    /**
     * Every space this facility user has held, current and past.
     */
    public function occupancies(): HasMany
    {
        return $this->hasMany(Occupancy::class);
    }

    public function occupancy(): HasOne
    {
        return $this->hasOne(Occupancy::class)->where('status', 'active');
    }

    public function isSupervisor(): bool
    {
        return $this->role === Role::Supervisor;
    }

    public function isFacilityUser(): bool
    {
        return $this->role === Role::FacilityUser;
    }
}
