<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tenancy\Database\TenantSessionBinder;
use App\Domain\Tenancy\Queue\QueueTenancy;
use App\Domain\Tenancy\TenancyState;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/** Wires the tenant context into requests, jobs, migrations and the database session (ADR 0002). */
final class TenancyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Tracks what each database session holds, so it lives as long as the connections do.
        $this->app->singleton(TenantSessionBinder::class);
        // A fresh, empty context for every request and every queued job.
        $this->app->scoped(TenantContext::class);
        $this->app->singleton(QueueTenancy::class);
    }

    public function boot(): void
    {
        Event::listen(ConnectionEstablished::class, function (ConnectionEstablished $event): void {
            $this->app->make(TenantSessionBinder::class)
                ->connected($event->connection, $this->app->make(TenantContext::class)->state());
        });

        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event): void {
            $this->app->make(TenantSessionBinder::class)
                ->rolledBack($event->connection, $this->app->make(TenantContext::class)->state());
        });

        Queue::createPayloadUsing(fn (): array => $this->app->make(QueueTenancy::class)->payload());

        Event::listen(JobProcessing::class, function (JobProcessing $event): void {
            $this->app->make(QueueTenancy::class)->enter($event->job);
        });

        // Fired exactly once per attempt, whether the job succeeded or threw.
        Event::listen(JobAttempted::class, function (): void {
            $this->app->make(QueueTenancy::class)->leave();
        });

        // Migrations may move data across every workspace; the policies would otherwise hide it.
        $beforeMigrations = null;
        Event::listen(MigrationsStarted::class, function () use (&$beforeMigrations): void {
            $context = $this->app->make(TenantContext::class);
            $beforeMigrations = $context->state();
            $context->system('database migrations');
        });

        Event::listen(MigrationsEnded::class, function () use (&$beforeMigrations): void {
            $this->app->make(TenantContext::class)->restore($beforeMigrations ?? TenancyState::none());
            $beforeMigrations = null;
        });
    }
}
