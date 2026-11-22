<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of waste bin: Wheeler bin, Mammoth bin... Each company keeps its own list.
 */
#[Fillable(['name', 'is_active'])]
class BinType extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
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
     * Find by name (any case) or add it, e.g. from a spreadsheet import.
     */
    public static function findOrAdd(?string $name): ?static
    {
        $name = trim((string) $name);

        if ($name === '') {
            return null;
        }

        return static::whereRaw('lower(name) = ?', [mb_strtolower($name)])->first() ?? static::create(['name' => $name]);
    }
}
