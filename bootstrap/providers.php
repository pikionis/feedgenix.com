<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\IdentityServiceProvider;
use App\Providers\IngestServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    IngestServiceProvider::class,
    TenancyServiceProvider::class,
];
