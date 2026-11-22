<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A building or site with spaces that facility users occupy. Created by the super admin.
 */
#[Fillable(['name', 'address', 'phone', 'email', 'supervisor_id', 'is_active'])]
class Facility extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    public function spaces(): HasMany
    {
        return $this->hasMany(Space::class);
    }

    public function occupancies(): HasMany
    {
        return $this->hasMany(Occupancy::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Supervisors only see the facilities they look after.
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        if ($user->isSupervisor()) {
            $query->where('supervisor_id', $user->id);
        }
    }
}
