<?php

declare(strict_types=1);

use App\Domain\AI\AiPurpose;

/*
 * The shipped config/ai.php, as it is. A route to an unknown model, a model on an unknown provider, or
 * a price without the date it was read from the provider's page fails here, not in production.
 */

it('routes every purpose, only to models it defines, on providers it defines', function (): void {
    $models = (array) config('ai.models');
    $providers = array_keys((array) config('ai.providers'));

    foreach (AiPurpose::cases() as $purpose) {
        $route = (array) config("ai.routes.{$purpose->value}");
        expect($route)->not->toBe([]);
        foreach ($route as $model) {
            expect($models)->toHaveKey($model)
                ->and($providers)->toContain($models[$model]['provider']);
        }
    }
});

it('only names a price as a decimal string with the date it was read, or not at all', function (): void {
    foreach ((array) config('ai.models') as $model => $entry) {
        $prices = [$entry['input'], $entry['output'], $entry['cached_input']];
        foreach ($prices as $price) {
            expect($price === null || (is_string($price) && preg_match('/^\d+(\.\d+)?$/', $price) === 1))->toBeTrue("{$model}: a price must be a decimal string or null");
        }
        if (array_filter($prices, static fn ($price): bool => $price !== null) !== []) {
            expect($entry['priced_on'])->toMatch('/^\d{4}-\d{2}-\d{2}$/', "{$model}: a price needs the date it was read");
        }
    }
});

it('ships with AI off and the fake provider off unless the environment says otherwise', function (): void {
    $source = (string) file_get_contents(config_path('ai.php'));

    expect($source)->toContain("TypedEnv::bool('FEEDGENIX_AI_ENABLED', false)")
        ->toContain("TypedEnv::bool('FEEDGENIX_AI_FAKE', false)")
        ->and(config('ai.outer_transaction_level'))->toBe(0);
});

it('keeps translation on one model, and gives reasoning purposes a fallback', function (): void {
    expect((array) config('ai.routes.translation'))->toHaveCount(1)
        ->and(count((array) config('ai.routes.mapping')))->toBeGreaterThan(1);
});
