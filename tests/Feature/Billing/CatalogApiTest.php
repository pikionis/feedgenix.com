<?php

declare(strict_types=1);

it('publishes the plans in ladder order with yearly totals and nothing internal', function (): void {
    $response = $this->getJson('/api/plans')->assertOk()
        ->assertJsonPath('recommended', 'pro')
        ->assertJsonPath('annual_discount', 0.25)
        ->assertJsonPath('plans.1.key', 'pro')
        ->assertJsonPath('plans.1.price_yearly_eur', 360)
        ->assertJsonPath('plans.1.limits.products_per_year', 600000);

    expect(array_column($response->json('plans'), 'key'))->toBe(['free', 'pro', 'ultra', 'custom'])
        ->and((string) $response->getContent())->not->toContain('internal')->not->toContain('beta_ai_budget');
});

it('publishes the language registry', function (): void {
    $languages = collect($this->getJson('/api/languages')->assertOk()->json('languages'))->keyBy('code');

    expect($languages)->toHaveCount(count(config('languages')))
        ->and($languages['el']['native_name'])->toBe('Ελληνικά')
        ->and($languages['ar']['direction'])->toBe('rtl')
        ->and($languages['pt-BR']['name'])->toBe('Portuguese (Brazil)');
});
