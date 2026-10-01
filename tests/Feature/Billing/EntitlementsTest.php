<?php

declare(strict_types=1);

use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanLimitExceeded;
use App\Domain\Billing\TenantEntitlements;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

function rights(Tenant $tenant): TenantEntitlements
{
    return Entitlements::for($tenant->fresh() ?? $tenant);
}

function limitError(callable $callback): PlanLimitExceeded
{
    try {
        $callback();
    } catch (PlanLimitExceeded $exceeded) {
        return $exceeded;
    }

    throw new RuntimeException('Expected a PlanLimitExceeded.');
}

it('treats a new workspace as Free', function (): void {
    expect(rights(Tenant::factory()->create())->plan())->toBe('free');
});

it('keeps a paid plan in force through the grace days, then falls back to Free', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-03-10 12:00', 'UTC'));
    $tenant = Tenant::factory()->onPlan('pro')->create(['plan_ends_at' => CarbonImmutable::parse('2027-03-10 12:00', 'UTC')]);

    $this->travelTo(CarbonImmutable::parse('2027-03-13 11:59', 'UTC')); // ends + 3 days − 1 minute
    expect(rights($tenant)->plan())->toBe('pro');

    $this->travelTo(CarbonImmutable::parse('2027-03-13 12:01', 'UTC')); // ends + 3 days + 1 minute
    expect(rights($tenant)->plan())->toBe('free');
});

it('drops an expired plan to Free at once', function (): void {
    $tenant = Tenant::factory()->onPlan('ultra')->create(['plan_status' => 'expired']);

    expect(rights($tenant)->plan())->toBe('free')->and(rights($tenant)->limit('languages'))->toBe(1);
});

it('exempts internal workspaces from every limit', function (): void {
    $tenant = Tenant::factory()->create(['is_internal' => true]);

    expect(rights($tenant)->plan())->toBe('custom')
        ->and(rights($tenant)->limit('languages'))->toBeNull()
        ->and(rights($tenant)->can('api_mcp'))->toBeTrue();
});

it('lets a deal override win over the plan, including unlimited', function (): void {
    $tenant = Tenant::factory()->onPlan('pro')->create(['limits_override' => ['languages' => 7, 'feeds' => null]]);

    expect(rights($tenant)->limit('languages'))->toBe(7)
        ->and(rights($tenant)->limit('feeds'))->toBeNull()
        ->and(rights($tenant)->limit('worker_slots'))->toBe(2);
});

it('falls back to internal guards and refuses unknown limits', function (): void {
    $tenant = Tenant::factory()->create();

    expect(rights($tenant)->limit('manual_refresh_per_day'))->toBe(3)
        ->and(fn (): ?int => rights($tenant)->limit('no_such_limit'))->toThrow(InvalidArgumentException::class);
});

it('gives a yearly plan one annual pool of twelve months', function (): void {
    $this->travelTo(CarbonImmutable::parse('2027-05-20 09:00', 'UTC'));
    $tenant = Tenant::factory()->onPlan('pro', 'year')->create(['plan_started_at' => CarbonImmutable::parse('2027-02-01 00:00', 'UTC')]);

    $period = rights($tenant)->period();

    expect(rights($tenant)->interval())->toBe('year')
        ->and(rights($tenant)->productAllowance())->toBe(600_000)
        ->and($period['start']->toDateString())->toBe('2027-02-01')
        ->and($period['end']->toDateString())->toBe('2028-02-01');
});

it('slices months from the allowance anchor without month-end drift', function (string $now, string $start, string $end): void {
    $tenant = Tenant::factory()->create(['usage_anchor_at' => CarbonImmutable::parse('2027-01-31 10:00', 'UTC')]);
    $this->travelTo(CarbonImmutable::parse($now, 'UTC'));

    $slice = rights($tenant)->monthSlice();

    expect([$slice['start']->toDateString(), $slice['end']->toDateString()])->toBe([$start, $end]);
})->with([
    'first month' => ['2027-02-10 00:00', '2027-01-31', '2027-02-28'],
    'short month' => ['2027-03-01 00:00', '2027-02-28', '2027-03-31'],
    'back to 31' => ['2027-03-31 12:00', '2027-03-31', '2027-04-30'],
    'a year on' => ['2028-02-29 12:00', '2028-02-29', '2028-03-31'],
]);

it('suggests the cheapest plan that fits, or none', function (): void {
    $free = rights(Tenant::factory()->create());
    $custom = rights(Tenant::factory()->onPlan('custom')->create());

    expect($free->upgradeFor('languages', 2))->toBe('pro')
        ->and($free->upgradeFor('languages', 7))->toBe('ultra')
        ->and($free->upgradeFor('languages', 11))->toBe('custom')
        ->and($custom->upgradeFor('languages', 1000))->toBeNull()
        ->and($free->upgradeForFeature('field_instructions'))->toBe('pro')
        ->and($free->upgradeForFeature('api_mcp'))->toBe('custom');
});

it('explains a limit with the numbers and the plan that would fit', function (): void {
    $error = limitError(fn () => rights(Tenant::factory()->create())->assertWithin('languages', adding: 2, used: 0));

    expect($error->payload())->toBe([
        'error' => 'plan_limit',
        'limit' => 'languages',
        'allowed' => 1,
        'used' => 0,
        'requested' => 2,
        'plan' => 'free',
        'upgrade_to' => 'pro',
        'message' => 'Your Free plan includes 1 target language. Pro gives you 5 target languages.',
    ]);
});

it('explains a feature on a higher plan', function (): void {
    $error = limitError(fn () => rights(Tenant::factory()->create())->assertCan('field_instructions'));

    expect($error->payload())->toMatchArray([
        'error' => 'plan_feature',
        'limit' => 'field_instructions',
        'upgrade_to' => 'pro',
        'message' => 'Per-field instructions is part of Pro.',
    ]);
});

it('renders as HTTP 402 with exactly the public payload', function (): void {
    $response = limitError(fn () => rights(Tenant::factory()->create())->assertWithin('languages', adding: 3, used: 0))->render();

    expect($response->getStatusCode())->toBe(402)
        ->and(array_keys((array) $response->getData(true)))->toBe(['error', 'limit', 'allowed', 'used', 'requested', 'plan', 'upgrade_to', 'message']);
});

it('counts a workspace\'s own target languages only', function (): void {
    [$a, $b] = [Tenant::factory()->onPlan('pro')->create(), Tenant::factory()->create()];
    addLanguage($a, 'de');
    addLanguage($a, 'fr');
    addLanguage($b, 'it');

    expect(rights($a)->used('languages'))->toBe(2)
        ->and(rights($b)->used('languages'))->toBe(1)
        ->and(rights($a)->remaining('languages'))->toBe(3);
});
