<?php

namespace App\Http\Middleware;

use App\Tenancy\CurrentCompany;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Company pages (facilities, invoices, payments...) need a company. The super admin belongs to none:
 * they can use these pages once they open a company, and are sent to the platform area otherwise.
 */
class EnsureUserHasCompany
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user?->isPlatformAdmin() && CurrentCompany::id()) {
            return $next($request);
        }

        if ($user && ! $user->company_id) {
            return $user->isPlatformAdmin()
                ? redirect()->route('platform.companies')
                : abort(403, 'This account does not belong to a company.');
        }

        return $next($request);
    }
}
