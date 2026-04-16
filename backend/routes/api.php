<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\ExportController;
use App\Http\Controllers\Api\MappingController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\SubscriptionController;
use App\Http\Controllers\Api\TranslationController;
use App\Http\Controllers\Api\UploadController;
use App\Http\Controllers\Api\UsageController;
use App\Http\Controllers\Webhook\StripeWebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public Routes
|--------------------------------------------------------------------------
*/

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Authenticated Routes (Sanctum)
|--------------------------------------------------------------------------
*/

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/user', [AuthController::class, 'user']);

    /*
    |----------------------------------------------------------------------
    | Team-Scoped Routes
    |----------------------------------------------------------------------
    */
    Route::middleware(['scope.team'])->group(function () {

        // Dashboard & Usage
        Route::get('/dashboard', [DashboardController::class, 'index']);
        Route::get('/usage', [UsageController::class, 'index']);

        // Projects CRUD
        Route::apiResource('projects', ProjectController::class);

        // Upload (requires active subscription)
        Route::middleware(['check.subscription'])->group(function () {
            Route::post('/projects/{project}/upload', [UploadController::class, 'store']);
        });

        // Mapping
        Route::get('/projects/{project}/mapping', [MappingController::class, 'show']);
        Route::put('/projects/{project}/mapping', [MappingController::class, 'update']);

        // Translation (requires subscription + usage check)
        Route::middleware(['check.subscription', 'check.usage'])->group(function () {
            Route::post('/projects/{project}/translate', [TranslationController::class, 'start']);
        });
        Route::get('/projects/{project}/status', [TranslationController::class, 'status']);
        Route::get('/projects/{project}/preview', [TranslationController::class, 'preview']);

        // Export
        Route::get('/projects/{project}/export', [ExportController::class, 'download']);

        // Subscription
        Route::get('/subscription', [SubscriptionController::class, 'show']);
        Route::post('/subscription', [SubscriptionController::class, 'create']);
        Route::put('/subscription', [SubscriptionController::class, 'update']);
        Route::delete('/subscription', [SubscriptionController::class, 'cancel']);
        Route::get('/subscription/portal', [SubscriptionController::class, 'portal']);
    });
});

/*
|--------------------------------------------------------------------------
| Stripe Webhook (no auth)
|--------------------------------------------------------------------------
*/

Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handleWebhook']);
