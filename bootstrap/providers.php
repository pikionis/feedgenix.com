<?php

declare(strict_types=1);

use App\Providers\AiServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\CatalogServiceProvider;
use App\Providers\ExportServiceProvider;
use App\Providers\IdentityServiceProvider;
use App\Providers\IngestServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AiServiceProvider::class,
    AppServiceProvider::class,
    CatalogServiceProvider::class,
    ExportServiceProvider::class,
    IdentityServiceProvider::class,
    IngestServiceProvider::class,
    TenancyServiceProvider::class,
];
