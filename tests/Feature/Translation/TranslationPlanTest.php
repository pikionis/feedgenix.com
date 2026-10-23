<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\ProductText;
use App\Domain\Translation\Models\TranslationMemory;
use App\Models\Tenant;

/*
 * The translation plan: which fields, into which languages, how much work, and its estimate in
 * credits. Legacy: one source language for the whole catalogue; the plan counted rows with no
 * source at all.
 */

/** A workspace with a synced German feed and two target languages. */
function syncedFeed(?string $csv = null): array
{
    $tenant = aiWorkspace();
    addLanguage($tenant, 'en');
    addLanguage($tenant, 'fr');
    $feed = profiledFeed($tenant, $csv ?? ALPINE_SHOP_CSV);
    approveMapping($tenant, $feed);

    return [$tenant, $feed];
}

function planOf(Tenant $tenant, int $feedId): array
{
    return test()->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feedId}/translation-plan")->assertOk()->json('translation_plan');
}

/** @return array<string, int> "field>target" => texts */
function workOf(array $plan): array
{
    $work = [];
    foreach ($plan['work'] as $line) {
        $work["{$line['field']}>{$line['target_locale']}"] = $line['texts'];
    }

    return $work;
}

it('offers every text field the feed has, in its default mode, into every workspace language', function (): void {
    [$tenant, $feed] = syncedFeed();

    $plan = planOf($tenant, $feed->id);

    $modes = collect($plan['fields'])->pluck('mode', 'field')->all();
    expect($plan['saved'])->toBeFalse()
        ->and($modes)->toBe(['care_instructions' => 'translate', 'color' => 'translate', 'description' => 'translate', 'material' => 'translate', 'size' => 'keep', 'title' => 'translate'])
        ->and(collect($plan['fields'])->firstWhere('field', 'title')['sources'])->toBe([['locale' => 'de', 'products' => 5]])
        ->and($plan['target_locales'])->toBe(['en', 'fr'])
        ->and(workOf($plan)['title>en'])->toBe(5)
        ->and(workOf($plan)['size>fr'])->toBe(5)
        ->and($plan['estimate'])->toBe(['available' => false, 'unavailable_reason' => 'ai_disabled', 'about_credits' => null, 'at_most_credits' => null])
        ->and($plan['run']['stage'])->toBe('plan');
});

it('estimates in credits, never in costs', function (): void {
    [$tenant, $feed] = syncedFeed();
    fakeAi();

    $response = test()->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feed->id}/translation-plan")->assertOk();

    $estimate = $response->json('translation_plan.estimate');
    expect($estimate['available'])->toBeTrue()
        ->and($estimate['about_credits'])->toBeGreaterThan(0)
        ->and($estimate['at_most_credits'])->toBeGreaterThanOrEqual($estimate['about_credits'])
        ->and((string) $response->getContent())->not->toContain('cost')->not->toContain('usd')->not->toContain('millicredit')->not->toContain('markup');
});

it('counts stale and failed translations as work, fresh and hand-written ones not, and repeats in memory as free', function (): void {
    [$tenant, $feed] = syncedFeed();
    $products = productsOf($tenant, $feed);
    translateTitle($tenant, $products['AO-1001'], 'de', 'Leather hiking boot');
    translateTitle($tenant, $products['AO-1002'], 'de', 'Rain jacket', 'manual');
    translateTitle($tenant, $products['AO-1003'], 'de', 'Backpack');
    tenancy()->run($tenant->id, function () use ($products): void {
        ProductText::query()->where('product_id', $products['AO-1003']->id)->where('locale', 'en')->update(['status' => 'stale']);
        $source = ProductText::query()->where('product_id', $products['AO-1004']->id)->where('field', 'title')->where('locale', 'de')->firstOrFail();
        TranslationMemory::query()->forceCreate(['field' => 'title', 'source_locale' => 'de', 'target_locale' => 'en', 'source_hash' => $source->source_hash, 'value' => 'Tent for two']);
    });

    $line = collect(planOf($tenant, $feed->id)['work'])->first(fn (array $line): bool => $line['field'] === 'title' && $line['target_locale'] === 'en');

    // 5 products: AO-1001 fresh (no work), AO-1002 by a person (never redone), AO-1003 stale, AO-1004 and AO-1005 missing.
    expect($line['texts'])->toBe(3)->and($line['memory'])->toBe(1);
});

it('translates each product from a language it has, not from one language for the whole catalogue', function (): void {
    $csv = "sku;title_de;title_fr;price\nM-1;Kissenbezug aus Leinen für das Sofa;;29.90\nM-2;;Plaid en laine pour le canapé et le lit;89.00\n";
    [$tenant, $feed] = syncedFeed($csv);

    $work = workOf(planOf($tenant, $feed->id));

    // Into English: both, each from its own language. Into French: only M-1 (M-2 has French itself).
    expect($work['title>en'])->toBe(2)->and($work['title>fr'])->toBe(1);
});

it('saves the choices, refuses a stale version, unknown fields and languages outside the workspace', function (): void {
    [$tenant, $feed] = syncedFeed();
    $url = "/api/v1/feeds/{$feed->id}/translation-plan";

    $saved = test()->actingAs(owner($tenant))->putJson($url, ['version' => 1, 'fields' => ['color' => 'keep', 'care_instructions' => 'skip'], 'target_locales' => ['fr']])->assertOk()->json('translation_plan');
    expect($saved['saved'])->toBeTrue()
        ->and($saved['version'])->toBe(2)
        ->and(collect($saved['fields'])->pluck('mode', 'field')->only(['color', 'care_instructions', 'title'])->all())->toBe(['care_instructions' => 'skip', 'color' => 'keep', 'title' => 'translate'])
        ->and($saved['target_locales'])->toBe(['fr'])
        ->and(collect($saved['work'])->pluck('target_locale')->unique()->values()->all())->toBe(['fr'])
        ->and(collect($saved['work'])->pluck('field')->all())->not->toContain('care_instructions');

    test()->actingAs(owner($tenant))->putJson($url, ['version' => 1, 'fields' => [], 'target_locales' => ['fr']])->assertStatus(409)->assertJsonPath('error', 'plan_changed');
    test()->actingAs(owner($tenant))->putJson($url, ['version' => 2, 'fields' => ['meta_title' => 'translate'], 'target_locales' => ['fr']])->assertUnprocessable();
    test()->actingAs(owner($tenant))->putJson($url, ['version' => 2, 'fields' => [], 'target_locales' => ['ja']])->assertUnprocessable();
    test()->actingAs(owner($tenant))->putJson($url, ['version' => 2, 'fields' => ['title' => 'optimize'], 'target_locales' => ['fr']])->assertUnprocessable();
    test()->actingAs(memberOf($tenant, 'member'))->putJson($url, ['version' => 2, 'fields' => [], 'target_locales' => ['fr']])->assertForbidden();
});

it("never shows another workspace's plan", function (): void {
    [$tenant, $feed] = syncedFeed();

    test()->actingAs(owner(aiWorkspace()))->getJson("/api/v1/feeds/{$feed->id}/translation-plan")->assertNotFound();
});
