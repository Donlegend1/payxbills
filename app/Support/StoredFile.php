<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sends an uploaded file (proof of payment, memo picture) to view in the browser, or as a
 * download when the URL has ?download=1.
 */
class StoredFile
{
    public static function respond(Request $request, string $disk, string $path, string $name): StreamedResponse
    {
        $storage = Storage::disk($disk);
        abort_unless($storage->exists($path), 404);

        if ($request->boolean('download')) {
            return $storage->download($path, $name);
        }

        return $storage->response($path, $name, [
            // Uploaded files are shown, never run: no sniffing, and no scripts even in a PDF or HTML file
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "sandbox; default-src 'none'; img-src 'self' data:; style-src 'unsafe-inline'; object-src 'self'",
            'Cache-Control' => 'private, max-age=3600',
        ], 'inline');
    }
}
