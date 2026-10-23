<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Catalog\Schema\CanonicalSchema;
use Illuminate\Support\ServiceProvider;

final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CanonicalSchema::class, static fn (): CanonicalSchema => CanonicalSchema::fromConfig());
    }
}
