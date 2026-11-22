<?php

namespace App\Tenancy;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Limits queries to the current company's rows. With no current company (platform admin,
 * console before a company is chosen, webhooks before the record is found) nothing is filtered.
 */
class CompanyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if ($id = CurrentCompany::id()) {
            $builder->where($model->qualifyColumn('company_id'), $id);
        }
    }
}
