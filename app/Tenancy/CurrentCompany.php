<?php

namespace App\Tenancy;

use App\Models\Company;

/**
 * The company whose data the current request, job or command works with.
 *
 * Set from the logged-in user by SetCurrentCompany, from the record behind a signed link or webhook,
 * or by CurrentCompany::run() in console commands. While it is set, every model using
 * BelongsToCompany only sees and creates rows for that company.
 */
class CurrentCompany
{
    protected ?Company $company = null;

    public static function get(): ?Company
    {
        return app(self::class)->company;
    }

    public static function id(): ?int
    {
        return app(self::class)->company?->id;
    }

    public static function set(?Company $company): void
    {
        app(self::class)->company = $company;
    }

    /**
     * Run $callback as $company, then restore whichever company was current before.
     *
     * @template T
     *
     * @param  callable(Company): T  $callback
     * @return T
     */
    public static function run(Company $company, callable $callback): mixed
    {
        $previous = self::get();
        self::set($company);

        try {
            return $callback($company);
        } finally {
            self::set($previous);
        }
    }
}
