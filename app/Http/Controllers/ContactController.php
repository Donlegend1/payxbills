<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;

class ContactController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Public/Contact', [
            'status' => session('status'),
        ]);
    }

    /**
     * Accept a quote request or enquiry from a visitor.
     */
    public function store(Request $request): RedirectResponse
    {
        $enquiry = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'service' => ['nullable', 'string', 'max:100'],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        // TODO: store enquiries or email them to the office once mail is configured.
        Log::info('Website enquiry', $enquiry);

        return redirect()->route('contact')->with('status', 'Thank you. Our team will contact you within one working day.');
    }
}
