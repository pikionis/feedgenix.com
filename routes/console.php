<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Expired uploads and leftover work files (abandoned 2 GB uploads must not fill the disk).
Schedule::command('ingest:prune')->dailyAt('03:30')->withoutOverlapping()->onOneServer();

// The one reconciler (ADR 0006): expired leases, cleared holds, finished runs, then the dispatcher.
Schedule::command('ops:reconcile')->everyMinute()->withoutOverlapping()->onOneServer();

// Reservations a crashed worker never settled: charge what they consumed, release the rest.
Schedule::command('credits:settle-expired')->everyFiveMinutes()->withoutOverlapping()->onOneServer();

// Plans that lapsed by date: pause what is over the limits, resume what fits again (ADR 0024).
Schedule::command('plans:apply')->hourly()->withoutOverlapping()->onOneServer();
