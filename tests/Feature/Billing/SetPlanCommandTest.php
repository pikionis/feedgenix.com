<?php

declare(strict_types=1);

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

function setPlan(array $arguments): int
{
    return Artisan::call('tenants:set-plan', $arguments, new BufferedOutput);
}

it('puts a workspace on yearly Pro until a date', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-01-10 09:00', 'UTC'));
    $tenant = Tenant::factory()->create();

    expect(setPlan(['tenant' => $tenant->slug, 'plan' => 'pro', '--interval' => 'year', '--until' => '2028-01-09']))->toBe(0);

    $fresh = $tenant->fresh();
    expect($fresh?->plan)->toBe('pro')
        ->and($fresh?->billing_interval)->toBe('year')
        ->and($fresh?->plan_started_at?->toDateTimeString())->toBe('2027-01-10 09:00:00')
        ->and($fresh?->plan_ends_at?->toDateTimeString())->toBe('2028-01-09 23:59:59');
});

it('keeps the start of the term when only extending it', function (): void {
    $tenant = Tenant::factory()->onPlan('pro')->create(['plan_started_at' => CarbonImmutable::parse('2026-12-01', 'UTC')]);

    setPlan(['tenant' => (string) $tenant->id, 'plan' => 'pro', '--until' => '2027-12-01']);

    expect($tenant->fresh()?->plan_started_at?->toDateString())->toBe('2026-12-01');
});

it('returns a workspace to Free and clears its period', function (): void {
    $tenant = Tenant::factory()->onPlan('ultra', 'year')->create();

    setPlan(['tenant' => $tenant->slug, 'plan' => 'free']);

    $fresh = $tenant->fresh();
    expect([$fresh?->plan, $fresh?->billing_interval, $fresh?->plan_started_at, $fresh?->plan_ends_at])->toBe(['free', null, null, null]);
});

it('adds, unlimits and removes deal overrides', function (): void {
    $tenant = Tenant::factory()->create();

    setPlan(['tenant' => $tenant->slug, 'plan' => 'pro', '--override' => ['languages=7', 'feeds=null']]);
    // jsonb keeps keys in its own order: compare as a map.
    expect($tenant->fresh()?->limits_override)->toEqual(['languages' => 7, 'feeds' => null]);

    setPlan(['tenant' => $tenant->slug, 'plan' => 'pro', '--override' => ['languages=-']]);
    expect($tenant->fresh()?->limits_override)->toBe(['feeds' => null]);
});

it('refuses bad input and changes nothing', function (array $arguments): void {
    $tenant = Tenant::factory()->create(['slug' => 'target-shop']);

    expect(setPlan($arguments))->toBe(2);

    expect($tenant->fresh()?->plan)->toBe('free')->and($tenant->fresh()?->limits_override)->toBeNull();
})->with([
    'unknown plan' => [['tenant' => 'target-shop', 'plan' => 'platinum']],
    'unknown workspace' => [['tenant' => 'nobody', 'plan' => 'pro']],
    'bad interval' => [['tenant' => 'target-shop', 'plan' => 'pro', '--interval' => 'week']],
    'bad date' => [['tenant' => 'target-shop', 'plan' => 'pro', '--until' => '2027-02-30']],
    'unknown limit' => [['tenant' => 'target-shop', 'plan' => 'pro', '--override' => ['rockets=3']]],
    'bad value' => [['tenant' => 'target-shop', 'plan' => 'pro', '--override' => ['languages=many']]],
]);
