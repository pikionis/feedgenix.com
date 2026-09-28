<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Models\Tenant;

/** Entry point: Entitlements::for($tenant)->assertWithin('languages', adding: 2). */
final class Entitlements
{
    public static function for(Tenant|int $tenant): TenantEntitlements
    {
        return new TenantEntitlements($tenant instanceof Tenant ? $tenant : Tenant::query()->findOrFail($tenant));
    }
}
