<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\App\FeedController;
use App\Http\Controllers\App\FeedImportController;
use App\Http\Controllers\App\MappingController;
use App\Http\Controllers\App\ProfileController;
use App\Http\Controllers\App\TranslationPlanController;
use App\Http\Controllers\App\TwoFactorController;
use App\Http\Controllers\App\UploadController;
use App\Http\Controllers\App\WorkspaceController;
use App\Http\Controllers\Auth\AuthOptionsController;
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
    Route::get('/options', AuthOptionsController::class)->name('auth.options');
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

    // Two-factor sign-in (staff for now).
    Route::prefix('security/two-factor')->group(function (): void {
        Route::get('/', [TwoFactorController::class, 'show'])->name('two-factor.show');
        Route::post('/', [TwoFactorController::class, 'begin'])->name('two-factor.begin');
        Route::post('/confirm', [TwoFactorController::class, 'confirm'])->middleware('throttle:two-factor')->name('two-factor.confirm');
        Route::post('/challenge', [TwoFactorController::class, 'challenge'])->middleware('throttle:two-factor')->name('two-factor.challenge');
        Route::post('/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('two-factor.recovery-codes');
    });

    // The staff admin API: two-factor proven in this session, system context (sees every workspace).
    Route::middleware('staff')->prefix('admin')->group(function (): void {
        Route::get('/overview', OverviewController::class)->name('admin.overview');
    });

    // The workspace the person acts in: every query below is limited to it (ADR 0002).
    Route::middleware('tenant')->group(function (): void {
        Route::get('/workspace', [WorkspaceController::class, 'show'])->name('workspace.show');
        Route::put('/workspace', [WorkspaceController::class, 'update'])->name('workspace.update');
        Route::put('/workspace/languages', [WorkspaceController::class, 'updateLanguages'])->name('workspace.languages');

        // Feeds and their imports (M2).
        Route::get('/feeds', [FeedController::class, 'index'])->name('feeds.index');
        Route::post('/feeds', [FeedController::class, 'store'])->name('feeds.store');
        Route::get('/feeds/{feed}', [FeedController::class, 'show'])->name('feeds.show');
        Route::patch('/feeds/{feed}', [FeedController::class, 'update'])->name('feeds.update');
        Route::delete('/feeds/{feed}', [FeedController::class, 'destroy'])->name('feeds.destroy');
        Route::get('/feeds/{feed}/imports', [FeedImportController::class, 'index'])->name('feeds.imports');
        Route::post('/feeds/{feed}/fetch', [FeedImportController::class, 'fetch'])->middleware('throttle:feed-fetch')->name('feeds.fetch');
        Route::post('/feeds/{feed}/uploads', [UploadController::class, 'start'])->name('uploads.start');
        Route::scopeBindings()->group(function (): void {
            Route::put('/feeds/{feed}/uploads/{upload}/chunks/{index}', [UploadController::class, 'chunk'])->whereUuid('upload')->whereNumber('index')->name('uploads.chunk');
            Route::post('/feeds/{feed}/uploads/{upload}/complete', [UploadController::class, 'complete'])->whereUuid('upload')->name('uploads.complete');
        });
        // The mapping of a feed's columns to canonical fields (M3).
        Route::get('/feeds/{feed}/mapping', [MappingController::class, 'show'])->name('mapping.show');
        Route::patch('/feeds/{feed}/mapping', [MappingController::class, 'language'])->name('mapping.language');
        Route::put('/feeds/{feed}/mapping/fields', [MappingController::class, 'update'])->name('mapping.update');
        Route::post('/feeds/{feed}/mapping/suggest', [MappingController::class, 'suggest'])->name('mapping.suggest');
        Route::post('/feeds/{feed}/mapping/approve', [MappingController::class, 'approve'])->name('mapping.approve');
        // What a feed translates, into which languages (M4).
        Route::get('/feeds/{feed}/translation-plan', [TranslationPlanController::class, 'show'])->name('translation-plan.show');
        Route::put('/feeds/{feed}/translation-plan', [TranslationPlanController::class, 'update'])->name('translation-plan.update');
        Route::post('/feeds/{feed}/translation-plan/start', [TranslationPlanController::class, 'start'])->name('translation-plan.start');
        Route::get('/imports/{import}', [FeedImportController::class, 'show'])->name('imports.show');
        Route::post('/imports/{import}/reread', [FeedImportController::class, 'reread'])->name('imports.reread');
    });
});
