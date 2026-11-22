<?php

namespace Tests;

use App\Models\Company;
use App\Tenancy\CurrentCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** The company tests work in unless they create another. */
    protected ?Company $company = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Data created in a test belongs to one company, as it would for a logged-in user
        if (in_array(RefreshDatabase::class, class_uses_recursive(static::class), true)) {
            $this->company = Company::create(['name' => 'Test Company']);
            CurrentCompany::set($this->company);
        }
    }
}
