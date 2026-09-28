<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\GoogleController;
use Illuminate\Support\Facades\Route;

/*
 * The SPA (frontend/) is served by nginx in production and by Vite locally, on the same origin as
 * the API. Only routes that need a server-side redirect with a session live here.
 */

// Google sign-in: the session carries the OAuth state and the invite code.
Route::middleware('throttle:oauth')->prefix('auth/google')->group(function (): void {
    Route::get('/redirect', [GoogleController::class, 'redirect'])->name('auth.google.redirect');
    Route::get('/callback', [GoogleController::class, 'callback'])->name('auth.google.callback');
});
