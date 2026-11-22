<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Tenancy\CurrentCompany;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The company logo: uploaded by the company admin on the Settings page, shown on receipts,
 * invoices, the pay page and in the app.
 */
class CompanyLogoController extends Controller
{
    /**
     * Served through the app rather than /storage, so it works without `storage:link` and on any domain.
     * Open to everyone: receipts and pay pages are opened without logging in.
     */
    public function show(Company $company): Response
    {
        $disk = Storage::disk(Company::LOGO_DISK);

        abort_unless($company->logo_path && $disk->exists($company->logo_path), 404);

        return $disk->response($company->logo_path, null, [
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        // No SVG: it can carry scripts and is served from our own domain
        $request->validate([
            'logo' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:2048', 'dimensions:min_width=32,min_height=32,max_width=4000,max_height=4000'],
        ], [
            'logo.mimes' => 'Upload a PNG, JPG or WebP image.',
            'logo.max' => 'The logo must be 2 MB or smaller.',
        ]);

        $company = CurrentCompany::get();
        $old = $company->logo_path;

        $company->update(['logo_path' => $request->file('logo')->store("logos/{$company->id}", Company::LOGO_DISK)]);

        if ($old) {
            Storage::disk(Company::LOGO_DISK)->delete($old);
        }

        return back()->with('success', 'Logo saved. It now shows on your receipts, invoices and pay page.');
    }

    public function destroy(): RedirectResponse
    {
        $company = CurrentCompany::get();

        if ($company->logo_path) {
            Storage::disk(Company::LOGO_DISK)->delete($company->logo_path);
            $company->update(['logo_path' => null]);
        }

        return back()->with('success', 'Logo removed. Your company name is shown on its own.');
    }
}
