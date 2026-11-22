<?php

namespace App\Models;

use App\Tenancy\BelongsToCompany;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A room, shop, stall or other space in a facility. At most one facility user occupies it at a time.
 */
#[Fillable(['facility_id', 'number', 'label', 'is_active'])]
class Space extends Model
{
    use BelongsToCompany;

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function facility(): BelongsTo
    {
        return $this->belongsTo(Facility::class);
    }

    public function occupancies(): HasMany
    {
        return $this->hasMany(Occupancy::class);
    }

    public function currentOccupancy(): HasOne
    {
        return $this->hasOne(Occupancy::class)->where('status', 'active');
    }

    /**
     * Active spaces with nobody in them.
     */
    public function scopeVacant(Builder $query): void
    {
        $query->where('is_active', true)->whereDoesntHave('occupancies', fn (Builder $q) => $q->where('status', 'active'));
    }

    /**
     * "Room 12" or just "12".
     */
    public function name(): string
    {
        return trim(($this->label ? $this->label.' ' : '').$this->number);
    }
}
