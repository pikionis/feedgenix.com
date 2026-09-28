<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\IdentityServiceProvider;
use App\Providers\TenancyServiceProvider;

return [
    AppServiceProvider::class,
    IdentityServiceProvider::class,
    TenancyServiceProvider::class,
];
