<?php

declare(strict_types=1);

use App\Domain\Billing\PlanCatalog;

it('has exactly the plans of the upgrade ladder, so none is unreachable', function (): void {
    expect(PlanCatalog::keys())->toBe(PlanCatalog::LADDER);
});

it('prices yearly as twelve discounted months paid once', function (string $plan, float $perMonth, float $year, float $savings): void {
    $public = PlanCatalog::publicPlan($plan);

    expect([$public['price_yearly_per_month_eur'], $public['price_yearly_eur'], $public['yearly_savings_eur']])
        ->toBe([$perMonth, $year, $savings]);
})->with([
    'free' => ['free', 0.0, 0.0, 0.0],
    'pro' => ['pro', 30.0, 360.0, 120.0],
    'ultra' => ['ultra', 75.0, 900.0, 300.0],
    'custom' => ['custom', 135.0, 1620.0, 540.0],
]);

it('shows the annual product pool as twelve months', function (): void {
    expect(PlanCatalog::publicPlan('pro')['limits']['products_per_year'])->toBe(600_000)
        ->and(PlanCatalog::publicPlan('ultra')['limits']['products_per_year'])->toBeNull();
});

it('never exposes internal guards, costs or the markup in a public plan', function (string $plan): void {
    $public = PlanCatalog::publicPlan($plan);
    $json = (string) json_encode($public);

    expect(array_keys($public['limits']))->toBe(['feeds', 'languages', 'products_per_month', 'products_per_year', 'worker_slots'])
        ->and($json)->not->toContain('internal')
        ->not->toContain('beta_ai_budget')
        ->not->toContain('max_rows')
        ->not->toContain('markup')
        ->not->toContain('cost');
})->with(PlanCatalog::LADDER);

it('defines the same features on every plan, and every strong feature on Pro', function (): void {
    $features = array_map(static fn (string $plan): array => array_keys(PlanCatalog::publicPlan($plan)['features']), PlanCatalog::LADDER);

    expect(array_unique(array_map(serialize(...), $features)))->toHaveCount(1);

    foreach (['ai_agent', 'rag_memory', 'field_instructions', 'model_choice', 'priority_queue'] as $feature) {
        expect(PlanCatalog::feature('pro', $feature))->toBeTrue();
    }
});

it('charges overage only where the plan allows it', function (): void {
    expect(PlanCatalog::overageCreditsPerProduct('free'))->toBeNull()
        ->and(PlanCatalog::overageCreditsPerProduct('pro'))->toBe(10)
        ->and(PlanCatalog::overageCreditsPerProduct('ultra'))->toBeNull();
});
