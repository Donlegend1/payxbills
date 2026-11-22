<?php

namespace App\Http\Controllers;

use App\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\Model;

abstract class Controller
{
    /**
     * Signed links (pay page, receipts) open without logging in, so no company is current yet.
     * Work as the company that owns $record, and refuse links of suspended companies.
     */
    protected function actAsCompanyOf(Model $record): void
    {
        $company = $record->company;

        abort_unless($company && $company->is_active, 404);

        CurrentCompany::set($company);
    }
}
