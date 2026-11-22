<?php

namespace App\Models;

use App\Enums\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A client organisation using PayXbills (e.g. Cosmopolitan Cleaners) to bill the users of its facilities.
 * Everything a company owns carries its company_id; see App\Tenancy\BelongsToCompany.
 */
#[Fillable(['name', 'slug', 'email', 'phone', 'address', 'logo_path', 'is_active', 'paystack_secret_key'])]
#[Hidden(['paystack_secret_key'])]
class Company extends Model
{
    public const LOGO_DISK = 'public';

    /**
     * Where the logo is served, or null when the company has none. The version changes with
     * every upload, so browsers never show an old logo.
     */
    public function logoUrl(): ?string
    {
        return $this->logo_path
            ? route('companies.logo', ['company' => $this->id, 'v' => substr(md5($this->logo_path), 0, 8)])
            : null;
    }

    /**
     * Name and logo, for receipts, invoices and the pay page.
     *
     * @return array{name: string, logo: string|null}
     */
    public function brand(): array
    {
        return ['name' => $this->name, 'logo' => $this->logoUrl()];
    }

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'paystack_secret_key' => 'encrypted'];
    }

    protected static function booted(): void
    {
        static::creating(function (Company $company) {
            $company->slug ??= static::uniqueSlug($company->name);
        });
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function facilities(): HasMany
    {
        return $this->hasMany(Facility::class);
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
     * The first active admin, used when platform staff log in to help a company.
     */
    public function primaryAdmin(): ?User
    {
        return User::acrossCompanies()
            ->where('company_id', $this->id)
            ->where('role', Role::Admin)
            ->where('is_active', true)
            ->orderBy('id')
            ->first();
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'company';
        $slug = $base;
        $n = 2;

        while (static::where('slug', $slug)->exists()) {
            $slug = $base.'-'.$n++;
        }

        return $slug;
    }
}
