<?php

namespace App\Tenancy;

use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Rule;

/**
 * Validation rules limited to the current company. Plain exists/unique rules query the table
 * directly and would accept another company's ids.
 */
class CompanyRules
{
    public static function exists(string $table, string $column = 'id'): Exists
    {
        return Rule::exists($table, $column)->where('company_id', CurrentCompany::id());
    }

    public static function unique(string $table, string $column = 'NULL'): Unique
    {
        return Rule::unique($table, $column)->where('company_id', CurrentCompany::id());
    }
}
