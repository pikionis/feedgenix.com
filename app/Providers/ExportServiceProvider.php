<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Export\Contracts\ArtifactServer;
use App\Domain\Export\LocalArtifactServer;
use Illuminate\Support\ServiceProvider;

final class ExportServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ArtifactServer::class, LocalArtifactServer::class);
    }
}
