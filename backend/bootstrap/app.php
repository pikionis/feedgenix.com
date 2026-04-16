<?php

use App\Http\Middleware\CheckFeature;
use App\Http\Middleware\CheckSubscription;
use App\Http\Middleware\CheckUsageLimit;
use App\Http\Middleware\ScopeToTeam;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'scope.team' => ScopeToTeam::class,
            'check.subscription' => CheckSubscription::class,
            'check.feature' => CheckFeature::class,
            'check.usage' => CheckUsageLimit::class,
        ]);

        $middleware->statefulApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
