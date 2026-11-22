<?php

namespace App\Http\Middleware;

use App\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes the logged-in user's company the current company, so every query in the request only
 * sees that company's data.
 *
 * Runs before SubstituteBindings (see bootstrap/app.php), so /facilities/{facility} cannot load
 * another company's facility.
 */
class SetCurrentCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        // Platform admins have no company: nothing is current and company pages are closed to them
        if ($user) {
            CurrentCompany::set($user->company);
        }

        return $next($request);
    }
}
