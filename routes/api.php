<?php

declare(strict_types=1);

use App\Http\Controllers\App\ProfileController;
use App\Http\Controllers\App\WorkspaceController;
use App\Http\Controllers\Auth\EmailVerificationController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\Public\CatalogController;
use App\Http\Controllers\Public\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * Every route here is authenticated unless it is listed in tests/Feature/Security/RouteSweepTest.php
 * with a reason (legacy defect D17: an unauthenticated stats route).
 */

Route::get('/health', HealthController::class)->name('health');

// Public reference data (pricing page, sign-up).
Route::get('/plans', [CatalogController::class, 'plans'])->name('catalog.plans');
Route::get('/languages', [CatalogController::class, 'languages'])->name('catalog.languages');

Route::prefix('auth')->group(function (): void {
    Route::post('/register', RegisterController::class)->middleware('throttle:register')->name('auth.register');
    Route::post('/login', [SessionController::class, 'store'])->middleware('throttle:login')->name('auth.login');
    Route::post('/forgot-password', [PasswordResetController::class, 'sendLink'])
        ->middleware('throttle:password-reset')->name('auth.password.email');
    Route::post('/reset-password', [PasswordResetController::class, 'reset'])
        ->middleware('throttle:password-reset')->name('auth.password.update');
    Route::get('/verify-email/{id}/{hash}', [EmailVerificationController::class, 'verify'])
        ->middleware(['signed', 'throttle:6,1'])->whereNumber('id')->name('verification.verify');
});

Route::middleware('auth:sanctum')->prefix('v1')->group(function (): void {
    // The account itself (touches no workspace data).
    Route::get('/auth/user', [SessionController::class, 'show'])->name('auth.user');
    Route::post('/auth/logout', [SessionController::class, 'destroy'])->name('auth.logout');
    Route::post('/auth/email/verification-notification', [EmailVerificationController::class, 'resend'])
        ->middleware('throttle:6,1')->name('verification.send');

    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::put('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/password', [ProfileController::class, 'updatePassword'])->name('profile.password');

    // The workspace the person acts in: every query below is limited to it (ADR 0002).
    Route::middleware('tenant')->group(function (): void {
        Route::get('/workspace', [WorkspaceController::class, 'show'])->name('workspace.show');
        Route::put('/workspace', [WorkspaceController::class, 'update'])->name('workspace.update');
        Route::put('/workspace/languages', [WorkspaceController::class, 'updateLanguages'])->name('workspace.languages');
    });
});
