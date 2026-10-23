<?php

declare(strict_types=1);

use App\Domain\AI\Data\AiRequest;
use App\Domain\Catalog\Models\ProductText;
use App\Domain\Translation\Models\TranslationMemory;

/*
 * Reviewing translations (M4): the products of a feed in one language, what needs a look, and a
 * person's word on a text: write it, approve the AI's, or hand it back. Always fenced by the source
 * the person saw; never the feed's own text or a golden field.
 */

/** A German feed translated into English, the title of AO-1001 failing a hard check and AO-1002's flagged. */
function reviewedWorkspace(): array
{
    [$tenant, $feed, $fakes] = translatingWorkspace(['en']);
    $products = productsOf($tenant, $feed);
    $hard = 'p'.$products['AO-1001']->id;
    $soft = 'p'.$products['AO-1002']->id;
    (fn (): array => $this->script = [])->call($fakes['gemini']);
    $fakes['gemini']->push(...array_fill(0, 200, translator(static function (array $values, AiRequest $request) use ($hard, $soft): array {
        if (str_contains($request->prompt->instructions, 'the Title of')) {
            if (isset($values[$hard])) {
                $values[$hard] = ['text' => '[en] Leather hiking boot 999'];
            }
            if (isset($values[$soft])) {
                $values[$soft] = ['text' => 'Regenjacke mit Kapuze'];
            }
        }

        return $values;
    })));
    startTranslation($tenant, $feed);

    return [$tenant, $feed, $products, $fakes];
}

it('lists the texts in one language with their source, and shows what needs a look', function (): void {
    [$tenant, $feed] = reviewedWorkspace();

    $all = reviewList($tenant, $feed->id)->json();
    expect($all['meta']['counts'])->toBe(['all' => 5, 'review' => 2, 'missing' => 0, 'manual' => 0])
        ->and(collect($all['data'])->pluck('sku')->all())->toBe(['AO-1001', 'AO-1002', 'AO-1003', 'AO-1004', 'AO-1005']);

    $title = reviewText($all['data'][0], 'title');
    expect($title['source'])->toMatchArray(['locale' => 'de', 'value' => 'Wanderschuh aus Leder'])
        ->and($title['target'])->toMatchArray(['value' => '[en] Leather hiking boot 999', 'status' => 'qa_failed', 'origin' => 'ai', 'hard' => true, 'stale' => false])
        ->and($title['target']['failures'])->toContain('numbers')
        ->and(reviewText($all['data'][1], 'title')['target'])->toMatchArray(['status' => 'ready', 'failures' => ['echo'], 'hard' => false])
        // Only texts: no golden field is ever part of a review.
        ->and(collect($all['data'][0]['texts'])->pluck('field')->intersect(['sku', 'gtin', 'price', 'stock_quantity']))->toBeEmpty();

    expect(collect(reviewList($tenant, $feed->id, ['filter' => 'review'])->json('data'))->pluck('sku')->all())->toBe(['AO-1001', 'AO-1002'])
        ->and(collect(reviewList($tenant, $feed->id, ['search' => 'zelt'])->json('data'))->pluck('sku')->all())->toBe(['AO-1004'])
        ->and(collect(reviewList($tenant, $feed->id, ['search' => '1005'])->json('data'))->pluck('sku')->all())->toBe(['AO-1005'])
        // A wildcard is a character like any other.
        ->and(reviewList($tenant, $feed->id, ['search' => '%'])->json('data'))->toBe([]);
});

it('counts a text with no translation as missing, but never one the plan leaves out or the feed provides', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en', 'de']);

    expect(reviewList($tenant, $feed->id)->json('meta.counts.missing'))->toBe(5);

    $version = (int) test()->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feed->id}/translation-plan")->json('translation_plan.version');
    $fields = collect(test()->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feed->id}/translation-plan")->json('translation_plan.fields'))
        ->mapWithKeys(fn (array $field): array => [$field['field'] => 'skip'])->all();
    test()->actingAs(owner($tenant))->putJson("/api/v1/feeds/{$feed->id}/translation-plan", ['version' => $version, 'fields' => $fields, 'target_locales' => ['en']])->assertOk();

    expect(reviewList($tenant, $feed->id)->json('meta.counts.missing'))->toBe(0);
    $german = test()->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feed->id}/products?locale=de")->assertOk()->json();
    expect($german['meta']['counts']['missing'])->toBe(0)
        ->and(reviewText($german['data'][0], 'title'))->toMatchArray(['provided' => true, 'target' => null]);
});

it('keeps a person\'s translation for ever, and reuses it for the same text elsewhere', function (): void {
    [$tenant, $feed, $products, $fakes] = reviewedWorkspace();
    $title = reviewText(reviewList($tenant, $feed->id)->json('data.0'), 'title');

    $written = test()->actingAs(owner($tenant))->putJson("/api/v1/products/{$products['AO-1001']->id}/texts/title/en", [
        'value' => 'Leather hiking boot', 'source_locale' => 'de', 'source_hash' => $title['source']['hash'],
    ])->assertOk()->json('product');

    expect(reviewText($written, 'title')['target'])->toMatchArray(['value' => 'Leather hiking boot', 'status' => 'ready', 'origin' => 'manual', 'failures' => []])
        ->and(reviewList($tenant, $feed->id)->json('meta.counts'))->toMatchArray(['review' => 1, 'manual' => 1]);

    // Another run never touches it.
    tenancy()->run($tenant->id, fn () => ProductText::query()->where('role', 'target')->where('origin', '<>', 'manual')->delete());
    startTranslation($tenant, $feed);
    expect(reviewText(reviewList($tenant, $feed->id)->json('data.0'), 'title')['target']['value'])->toBe('Leather hiking boot');

    // The memory holds the person's version for the same German text.
    expect(tenancy()->run($tenant->id, fn () => TranslationMemory::query()->where('field', 'title')->where('target_locale', 'en')->where('source_hash', $title['source']['hash'])->value('value')))
        ->toBe('Leather hiking boot');
});

it('refuses a translation of a text that changed in the feed meanwhile, and writes nothing', function (): void {
    [$tenant, $feed, $products] = reviewedWorkspace();
    $title = reviewText(reviewList($tenant, $feed->id)->json('data.0'), 'title');
    tenancy()->run($tenant->id, fn () => ProductText::query()->where('product_id', $products['AO-1001']->id)->where('field', 'title')->where('locale', 'de')
        ->update(['value' => 'Wanderschuh aus Wildleder', 'source_hash' => ProductText::hashOf('Wanderschuh aus Wildleder')]));

    test()->actingAs(owner($tenant))->putJson("/api/v1/products/{$products['AO-1001']->id}/texts/title/en", [
        'value' => 'Leather hiking boot', 'source_locale' => 'de', 'source_hash' => $title['source']['hash'],
    ])->assertStatus(409)->assertJsonPath('error', 'source_changed');
    test()->actingAs(owner($tenant))->postJson("/api/v1/products/{$products['AO-1001']->id}/texts/title/en/approve", ['source_hash' => $title['source']['hash']])
        ->assertStatus(409);

    $now = reviewText(reviewList($tenant, $feed->id)->json('data.0'), 'title');
    expect($now['target'])->toMatchArray(['value' => '[en] Leather hiking boot 999', 'origin' => 'ai', 'stale' => true])
        ->and($now['source']['value'])->toBe('Wanderschuh aus Wildleder');
});

it('publishes a flagged translation once a person approves it, and only an AI translation', function (): void {
    [$tenant, $feed, $products] = reviewedWorkspace();
    $title = reviewText(reviewList($tenant, $feed->id)->json('data.0'), 'title');
    $url = "/api/v1/products/{$products['AO-1001']->id}/texts/title/en/approve";

    $approved = test()->actingAs(owner($tenant))->postJson($url, ['source_hash' => $title['source']['hash']])->assertOk()->json('product');
    expect(reviewText($approved, 'title')['target'])->toMatchArray(['status' => 'ready', 'origin' => 'approved', 'hard' => false])
        ->and(reviewList($tenant, $feed->id)->json('meta.counts.review'))->toBe(1);

    // Approved already: nothing by the AI left to approve.
    test()->actingAs(owner($tenant))->postJson($url, ['source_hash' => $title['source']['hash']])->assertUnprocessable();
});

it('hands a text back to the AI, which translates it again instead of the memory', function (): void {
    [$tenant, $feed, $products, $fakes] = reviewedWorkspace();
    $title = reviewText(reviewList($tenant, $feed->id)->json('data.1'), 'title');
    test()->actingAs(owner($tenant))->putJson("/api/v1/products/{$products['AO-1002']->id}/texts/title/en", [
        'value' => 'Hooded rain jacket', 'source_locale' => 'de', 'source_hash' => $title['source']['hash'],
    ])->assertOk();

    $back = test()->actingAs(owner($tenant))->deleteJson("/api/v1/products/{$products['AO-1002']->id}/texts/title/en")->assertOk()->json('product');
    expect(reviewText($back, 'title')['target'])->toBeNull();

    $calls = count($fakes['gemini']->calls);
    startTranslation($tenant, $feed);
    expect(count($fakes['gemini']->calls))->toBeGreaterThan($calls)
        ->and(reviewText(reviewList($tenant, $feed->id)->json('data.1'), 'title')['target']['origin'])->toBe('ai');
});

it('never touches the feed\'s own text, a golden field, a language outside the workspace, or another workspace', function (): void {
    [$tenant, $feed, $products] = reviewedWorkspace();
    addLanguage($tenant, 'de');
    $id = $products['AO-1001']->id;
    $hash = reviewText(reviewList($tenant, $feed->id)->json('data.0'), 'title')['source']['hash'];
    $write = fn (string $field, string $locale) => test()->actingAs(owner($tenant))->putJson("/api/v1/products/{$id}/texts/{$field}/{$locale}", [
        'value' => 'Something else', 'source_locale' => 'de', 'source_hash' => $hash,
    ]);

    $write('title', 'de')->assertUnprocessable();
    $write('sku', 'en')->assertUnprocessable();
    $write('price', 'en')->assertUnprocessable();
    $write('title', 'fr')->assertUnprocessable();
    test()->actingAs(owner($tenant))->deleteJson("/api/v1/products/{$id}/texts/title/de")->assertOk();

    $texts = textsOf($tenant, $products['AO-1001']);
    expect($texts['title@de']->value)->toBe('Wanderschuh aus Leder')
        ->and($texts['title@de']->role)->toBe('source')
        ->and(productsOf($tenant, $feed)['AO-1001']->source_values)->toBe($products['AO-1001']->source_values);

    // A product gone from the feed is not translated by hand either.
    tenancy()->run($tenant->id, fn () => $products['AO-1005']->forceFill(['status' => 'disappeared'])->save());
    $gone = textsOf($tenant, $products['AO-1005'])['title@de'];
    test()->actingAs(owner($tenant))->putJson("/api/v1/products/{$products['AO-1005']->id}/texts/title/en", [
        'value' => 'Winter sleeping bag', 'source_locale' => 'de', 'source_hash' => $gone->source_hash,
    ])->assertUnprocessable();

    $other = aiWorkspace();
    addLanguage($other, 'en');
    test()->actingAs(owner($other))->putJson("/api/v1/products/{$id}/texts/title/en", ['value' => 'x', 'source_locale' => 'de', 'source_hash' => $hash])->assertNotFound();
    test()->actingAs(owner($other))->getJson("/api/v1/feeds/{$feed->id}/products?locale=en")->assertNotFound();
});

it('lets members read the review and nothing more', function (): void {
    [$tenant, $feed, $products] = reviewedWorkspace();
    $member = memberOf($tenant, 'member');
    $id = $products['AO-1001']->id;

    test()->actingAs($member)->getJson("/api/v1/feeds/{$feed->id}/products?locale=en")->assertOk();
    test()->actingAs($member)->putJson("/api/v1/products/{$id}/texts/title/en", ['value' => 'x', 'source_locale' => 'de', 'source_hash' => str_repeat('a', 64)])->assertForbidden();
    test()->actingAs($member)->postJson("/api/v1/products/{$id}/texts/title/en/approve", ['source_hash' => str_repeat('a', 64)])->assertForbidden();
    test()->actingAs($member)->deleteJson("/api/v1/products/{$id}/texts/title/en")->assertForbidden();
    test()->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feed->id}/products?locale=not a locale")->assertUnprocessable();
});
