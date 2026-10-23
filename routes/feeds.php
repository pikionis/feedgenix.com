<?php

declare(strict_types=1);

use App\Http\Controllers\Public\FeedFileController;
use Illuminate\Support\Facades\Route;

/*
 * Feed URLs (ADR 0007): what sales channels pull. No session, no cookies, no CSRF: the token in the
 * URL is the only key. Rate limited per token.
 */
Route::get('/f/{token}/{file}', FeedFileController::class)
    ->where(['token' => '[A-Za-z0-9]{40}', 'file' => '[A-Za-z0-9._-]{1,120}'])
    ->middleware('throttle:feed-file')
    ->name('feeds.file');
