<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Export\Contracts\ArtifactServer;
use App\Domain\Export\LocalArtifactServer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

final class ExportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ArtifactServer::class, LocalArtifactServer::class);
    }

    public function boot(): void
    {
        // A channel pulls a feed a few times an hour; a token pulled faster is a script gone wrong.
        RateLimiter::for('feed-file', static fn (Request $request): Limit => Limit::perMinute(60)->by('feed-file:'.$request->route('token')));
    }
}
