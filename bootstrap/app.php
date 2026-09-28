<?php

declare(strict_types=1);

use App\Http\Middleware\EnsureStaff;
use App\Http\Middleware\ResolveTenant;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The SPA is served from the same origin: Sanctum's cookie session for API requests.
        $middleware->statefulApi();

        $middleware->alias([
            'tenant' => ResolveTenant::class,
            'staff' => EnsureStaff::class,
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
