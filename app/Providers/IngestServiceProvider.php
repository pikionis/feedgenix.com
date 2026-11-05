<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Ingest\Fetch\Fetcher;
use App\Domain\Ingest\Fetch\SourceFetcher;
use App\Domain\Ingest\IngestLimits;
use App\Domain\Ingest\Profile\ColumnProfiler;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class IngestServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(Fetcher::class, static fn (): Fetcher => SourceFetcher::make());
        $this->app->bind(IngestLimits::class, static fn (): IngestLimits => IngestLimits::fromConfig());
        $this->app->bind(ColumnProfiler::class, static fn (): ColumnProfiler => ColumnProfiler::fromConfig());
    }

    public function boot(): void
    {
        // "Fetch now": a person may not turn FeedGenix into a way to hammer someone else's server.
        RateLimiter::for('feed-fetch', static fn (Request $request): Limit => Limit::perMinute(10)
            ->by('feed-fetch:'.$request->user()?->getAuthIdentifier()));
    }
}
