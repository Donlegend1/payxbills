<?php

namespace App\Tenancy;

use App\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * For models whose rows belong to one company: queries only see the current company's rows,
 * and new rows are stamped with the current company.
 *
 * @mixin Model
 */
trait BelongsToCompany
{
    public static function bootBelongsToCompany(): void
    {
        static::addGlobalScope(new CompanyScope);

        static::creating(function (Model $model) {
            $model->company_id ??= CurrentCompany::id();

            if ($model->company_id === null && $model->requiresCompany()) {
                throw new LogicException('Cannot create '.class_basename($model).' without a company. Set CurrentCompany first.');
            }
        });
    }

    public function initializeBelongsToCompany(): void
    {
        $this->mergeFillable(['company_id']);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Query every company's rows (platform admin, webhooks, global uniqueness checks).
     */
    public static function acrossCompanies(): Builder
    {
        return static::withoutGlobalScope(CompanyScope::class);
    }

    /**
     * Whether a row must have a company. Users may not: platform admins belong to none.
     */
    public function requiresCompany(): bool
    {
        return true;
    }
}
