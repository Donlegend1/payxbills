<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Logs out deactivated users and users of suspended companies, and keeps users with an emailed
 * password on the My account page until they choose their own.
 */
class EnsureAccountIsUsable
{
    protected const ALLOWED_WHILE_CHANGING = ['profile.edit', 'password.update', 'logout', 'impersonate.leave'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->is_active === false) {
            return $this->logout($request, 'This account has been deactivated.');
        }

        // While a platform admin is logged in as a company user, the suspension does not lock them out
        if ($user?->company && ! $user->company->is_active && ! $request->session()->has('impersonator_id')) {
            return $this->logout($request, "{$user->company->name} is suspended on PayXbills. Please contact Fivestar Technologies.");
        }

        if ($user && $user->must_change_password && ! $request->routeIs(...self::ALLOWED_WHILE_CHANGING)) {
            return redirect()->route('profile.edit')->with('error', 'Please choose a new password to continue.');
        }

        return $next($request);
    }

    protected function logout(Request $request, string $message): Response
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
