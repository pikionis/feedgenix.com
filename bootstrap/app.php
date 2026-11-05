<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureFeedActive;
use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // Feed URLs: outside the web group, so a channel's pull gets no session or cookie.
        then: static function (): void {
            Route::group([], __DIR__.'/../routes/feeds.php');
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The SPA is served from the same origin: Sanctum's cookie session for API requests.
        $middleware->statefulApi();

        // A feed source's login reaches its server exactly as typed, spaces included (ADR 0030).
        $middleware->trimStrings(except: ['auth.username', 'auth.password', 'auth.value', 'auth.private_key', 'auth.passphrase']);

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'staff' => EnsureStaff::class,
            'feed.active' => EnsureFeedActive::class,
        ]);

        // The tenant context must exist before route-model binding runs, so a record from another
        // workspace resolves through the tenant scope and is a 404 (ADR 0002).
        $middleware->prependToPriorityList(SubstituteBindings::class, ResolveTenant::class);
        $middleware->prependToPriorityList(SubstituteBindings::class, EnsureStaff::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request): bool => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
