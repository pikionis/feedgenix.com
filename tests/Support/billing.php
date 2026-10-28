<?php

declare(strict_types=1);

use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

/* Billing helpers: a paid plan in force, and a plan change as staff make it. */

function onPro(Tenant $tenant): void
{
    $tenant->forceFill(['plan' => 'pro', 'billing_interval' => 'month', 'plan_started_at' => now(), 'plan_ends_at' => now()->addMonth()])->save();
}

function changePlan(Tenant $tenant, string $plan): string
{
    $output = new BufferedOutput;
    Artisan::call('tenants:set-plan', ['tenant' => (string) $tenant->id, 'plan' => $plan], $output);

    return $output->fetch();
}
