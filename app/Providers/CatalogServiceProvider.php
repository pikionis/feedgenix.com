<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Listeners\StartSyncOnNewData;
use App\Domain\Catalog\Schema\CanonicalSchema;
use App\Domain\Ingest\Events\ImportProfiled;
use App\Domain\Mapping\Events\MappingApproved;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CanonicalSchema::class, static fn (): CanonicalSchema => CanonicalSchema::fromConfig());
    }

    public function boot(): void
    {
        Event::listen(MappingApproved::class, StartSyncOnNewData::class);
        Event::listen(ImportProfiled::class, StartSyncOnNewData::class);
    }
}
