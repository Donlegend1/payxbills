<?php

use App\Http\Middleware\EnsureAccountIsUsable;
use App\Http\Middleware\EnsureUserHasCompany;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SetCurrentCompany;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SetCurrentCompany::class,
            AddLinkHeadersForPreloadedAssets::class,
            HandleInertiaRequests::class,
        ]);

        // The company must be known before route model binding loads {facility}, {invoice}...
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: SetCurrentCompany::class);

        $middleware->alias(['usable' => EnsureAccountIsUsable::class, 'company' => EnsureUserHasCompany::class]);

        $middleware->validateCsrfTokens(except: [
            'webhooks/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
