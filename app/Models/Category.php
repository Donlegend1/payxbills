<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A billing category created by the super admin. Facility users in it pay its monthly amount.
 */
#[Fillable(['name', 'monthly_amount', 'description', 'is_active'])]
class Category extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['monthly_amount' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function occupancies(): HasMany
    {
        return $this->hasMany(Occupancy::class);
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
