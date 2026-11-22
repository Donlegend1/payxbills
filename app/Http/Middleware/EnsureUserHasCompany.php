<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Company pages (facilities, invoices, payments...) need a company. Platform admins belong to none,
 * so they are sent to the platform area instead.
 */
class EnsureUserHasCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->company_id) {
            return $user->isPlatformAdmin()
                ? redirect()->route('platform.companies')
                : abort(403, 'This account does not belong to a company.');
        }

        return $next($request);
    }
}
