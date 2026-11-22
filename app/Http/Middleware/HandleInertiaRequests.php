<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Models\Company;
use App\Models\Setting;
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
                    'role_label' => $user->isPlatformAdmin() ? 'Super admin' : $user->role->label(),
                    'super_admin' => $user->isPlatformAdmin(),
                    'must_change_password' => $user->must_change_password,
                ] : null,
                // Abilities the frontend uses to show or hide menu items and buttons
                'can' => match (true) {
                    $user === null => [],
                    $user->isPlatformAdmin() => ['manage-companies', ...($company ? Role::superAdminAbilities() : [])],
                    default => $user->role->abilities(),
                },
                // The company the super admin has open (kept even on the all-companies pages)
                'managing' => $user?->isPlatformAdmin() && ($id = $request->session()->get(SetCurrentCompany::SESSION_KEY))
                    ? Company::whereKey($id)->first(['id', 'name'])?->only('id', 'name')
                    : null,
                // Set while a super admin is logged in as someone else
                'impersonator' => $request->session()->get('impersonator_name'),
            ],
            // The product, and the client company the logged-in user works for
            'platform' => config('app.platform'),
            'currentCompany' => $company ? ['id' => $company->id, ...$company->brand(), 'whatsapp' => Setting::get('whatsapp_number') ?: null] : null,
            'flash' => [
                'success' => fn () => $request->session()->get('success'),
                'error' => fn () => $request->session()->get('error'),
                'importErrors' => fn () => $request->session()->get('importErrors'),
            ],
        ];
    }
}
