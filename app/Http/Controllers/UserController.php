<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff accounts, managed by the super admin from the Users page.
 * Facility users are created on the Facility users page instead.
 */
class UserController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('App/Users', [
            'roles' => Role::options(),
            'users' => User::whereIn('role', Role::STAFF)->with('facilities:id,name,supervisor_id')->orderBy('name')->get()->map(fn (User $u) => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'phone' => $u->phone,
                'role' => $u->role->value,
                'role_label' => $u->role->label(),
                'is_active' => $u->is_active,
                'access' => $u->isSupervisor() ? ($u->facilities->pluck('name')->join(', ') ?: 'No facilities assigned') : $u->role->description(),
                'can_impersonate' => $this->canImpersonate($request->user(), $u),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(Role::STAFF)],
            'password' => ['required', Password::defaults()],
        ]);

        User::create($data + ['email_verified_at' => now(), 'is_active' => true]);

        return back()->with('success', "{$data['name']} can now log in.");
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        abort_if($user->isFacilityUser(), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user)],
            'phone' => ['nullable', 'string', 'max:30'],
            'role' => ['required', Rule::in(Role::STAFF)],
            'password' => ['nullable', Password::defaults()],
            'is_active' => ['boolean'],
        ]);

        if ($user->is($request->user()) && ($data['role'] !== Role::Admin->value || ! ($data['is_active'] ?? true))) {
            return back()->with('error', 'You cannot remove your own super admin access.');
        }

        if (empty($data['password'])) {
            unset($data['password']);
        }

        $user->update($data);

        return back()->with('success', "{$user->name} updated.");
    }

    /**
     * Log in as another staff member to see the system as they do.
     * The super admin's id is kept in the session so they can switch back.
     */
    public function impersonate(Request $request, User $user): RedirectResponse
    {
        if (! $this->canImpersonate($request->user(), $user)) {
            return back()->with('error', "You cannot log in as {$user->name}.");
        }

        $admin = $request->user();

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $request->session()->put(['impersonator_id' => $admin->id, 'impersonator_name' => $admin->name]);

        // Straight to their landing page (the dashboard would redirect again and drop the message)
        return redirect()->route($user->can('view-dashboard') ? 'dashboard' : 'facility-users')->with('success', "You are now logged in as {$user->name}.");
    }

    public function leaveImpersonation(Request $request): RedirectResponse
    {
        // A platform admin belongs to no company, so look beyond the current one
        $admin = User::acrossCompanies()->find($request->session()->pull('impersonator_id'));
        $request->session()->forget('impersonator_name');

        abort_unless($admin && $admin->is_active !== false && ($admin->isPlatformAdmin() || $admin->role === Role::Admin), 403);

        Auth::guard('web')->login($admin);
        $request->session()->regenerate();
        CurrentCompany::set($admin->company);

        return redirect()->route($admin->isPlatformAdmin() ? 'platform.companies' : 'users')->with('success', "Welcome back, {$admin->name}.");
    }

    /**
     * Only a super admin, not already impersonating, may log in as an active
     * staff member who is not themselves a super admin.
     */
    protected function canImpersonate(User $admin, User $user): bool
    {
        return $admin->role === Role::Admin
            && ! session()->has('impersonator_id')
            && ! $user->is($admin)
            && $user->role !== Role::Admin
            && $user->role->isStaff()
            && $user->is_active !== false;
    }
}
