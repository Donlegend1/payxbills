<?php

namespace App\Http\Middleware;

use App\Tenancy\CurrentCompany;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $company = CurrentCompany::get();

        return [
            ...parent::share($request),
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->value,
                    'role_label' => $user->isPlatformAdmin() ? 'Platform admin' : $user->role->label(),
                    'must_change_password' => $user->must_change_password,
                ] : null,
                // Abilities the frontend uses to show or hide menu items and buttons
                'can' => match (true) {
                    $user === null => [],
                    $user->isPlatformAdmin() => ['manage-companies'],
                    default => $user->role->abilities(),
                },
                // Set while a super admin is logged in as someone else
                'impersonator' => $request->session()->get('impersonator_name'),
            ],
            // The product, and the client company the logged-in user works for
            'platform' => config('app.platform'),
            'currentCompany' => $company ? ['id' => $company->id, ...$company->brand()] : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
            ],
        ];
    }
}
