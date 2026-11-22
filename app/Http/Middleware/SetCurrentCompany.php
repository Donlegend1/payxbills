<?php

namespace App\Http\Middleware;

use App\Models\Company;
use App\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the logged-in user's company the current company, so every query in the request only
 * sees that company's data. The super admin (no company of their own) works in whichever company
 * they opened from the platform area, or across all companies when none is open.
 *
 * Runs before SubstituteBindings (see bootstrap/app.php), so /facilities/{facility} cannot load
 * another company's facility.
 */
class SetCurrentCompany
{
    /** Session key holding the company the super admin opened. */
    public const SESSION_KEY = 'platform_company_id';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isPlatformAdmin()) {
            $opened = $request->session()->get(self::SESSION_KEY);
            CurrentCompany::set($opened ? Company::find($opened) : null);
        } elseif ($user) {
            CurrentCompany::set($user->company);
        }

        return $next($request);
    }
}
