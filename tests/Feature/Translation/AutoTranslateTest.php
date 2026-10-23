<?php

declare(strict_types=1);

use App\Domain\AI\Data\AiItem;
use App\Domain\AI\Data\AiRequest;
use App\Domain\Catalog\Jobs\SyncCatalogJob;
use App\Models\Tenant;

/*
 * A plan that carries on by itself (ADR 0021): each new file's new and changed texts are
 * translated without waiting for a person, through the same start a person presses. Off unless
 * someone turns it on; never without languages.
 */

/** Save the plan as shown, with automatic translation on or off. */
function saveAuto(Tenant $tenant, int $feedId, ?bool $auto, array $targets = ['en']): array
{
    $plan = test()->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feedId}/translation-plan")->json('translation_plan');
    $body = ['version' => $plan['version'], 'fields' => collect($plan['fields'])->mapWithKeys(fn (array $field): array => [$field['field'] => $field['mode']])->all(), 'target_locales' => $targets];

    return test()->actingAs(owner($tenant))->putJson("/api/v1/feeds/{$feedId}/translation-plan", $auto === null ? $body : [...$body, 'auto_translate' => $auto])
        ->assertOk()->json('translation_plan');
}

function uploadChangedTitle(Tenant $tenant, int $feedId): void
{
    uploadFeed(owner($tenant), $feedId, str_replace('Wanderschuh aus Leder', 'Wanderschuh aus Wildleder', ALPINE_SHOP_CSV), 'export.csv')->assertStatus(202);
}

it('translates the new and changed texts of a new file by itself, and only those', function (): void {
    [$tenant, $feed, $fakes] = translatingWorkspace(['en']);
    expect(saveAuto($tenant, $feed->id, true)['auto_translate'])->toBeTrue();
    startTranslation($tenant, $feed);
    $calls = count($fakes['gemini']->calls);

    uploadChangedTitle($tenant, $feed->id);

    $run = latestRun($tenant, $feed);
    $sent = collect(array_slice($fakes['gemini']->calls, $calls))->flatMap(fn (AiRequest $request): array => array_map(fn (AiItem $item): string => $item->text, $request->items))->all();
    expect([$run->stage, $run->status])->toBe(['done', 'done'])
        ->and($sent)->toBe(['Wanderschuh aus Wildleder'])
        ->and(reviewText(reviewList($tenant, $feed->id)->json('data.0'), 'title')['target']['value'])->toBe('[en] Wanderschuh aus Wildleder');
});

it('waits for a person when the plan does not carry on by itself, or has no language', function (): void {
    [$tenant, $feed, $fakes] = translatingWorkspace(['en']);
    startTranslation($tenant, $feed);
    $calls = count($fakes['gemini']->calls);

    uploadChangedTitle($tenant, $feed->id);
    expect([latestRun($tenant, $feed)->stage, latestRun($tenant, $feed)->status])->toBe(['plan', 'awaiting_decision']);

    saveAuto($tenant, $feed->id, true, targets: []);
    uploadFeed(owner($tenant), $feed->id, str_replace('Regenjacke', 'Regenmantel', ALPINE_SHOP_CSV), 'export.csv')->assertStatus(202);
    expect([latestRun($tenant, $feed)->stage, latestRun($tenant, $feed)->status])->toBe(['plan', 'awaiting_decision'])
        ->and(count($fakes['gemini']->calls))->toBe($calls);
});

it('keeps the choice when a save leaves it out, and lets only owners and admins change it', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);

    expect(saveAuto($tenant, $feed->id, null)['auto_translate'])->toBeFalse()
        ->and(saveAuto($tenant, $feed->id, true)['auto_translate'])->toBeTrue()
        ->and(saveAuto($tenant, $feed->id, null)['auto_translate'])->toBeTrue()
        ->and(saveAuto($tenant, $feed->id, false)['auto_translate'])->toBeFalse();

    $version = (int) test()->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feed->id}/translation-plan")->json('translation_plan.version');
    test()->actingAs(memberOf($tenant, 'member'))->putJson("/api/v1/feeds/{$feed->id}/translation-plan", ['version' => $version, 'fields' => [], 'target_locales' => ['en'], 'auto_translate' => true])
        ->assertForbidden();
});

it('holds the work of a file that arrives without credits, like a person\'s start would', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en'], credits: 1_000);
    saveAuto($tenant, $feed->id, true);

    uploadChangedTitle($tenant, $feed->id);

    expect(latestRun($tenant, $feed)->status)->toBe('held')
        ->and(collect(latestRun($tenant, $feed)->warnings)->pluck('code')->all())->toBe(['held_credits']);
});

it('leaves a run that went on to translate alone when its reading job is reported failed', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    startTranslation($tenant, $feed);
    $run = latestRun($tenant, $feed);

    (new SyncCatalogJob($run->id, $tenant->id))->failed(new RuntimeException('killed'));

    expect(latestRun($tenant, $feed)->status)->toBe('done')
        ->and(latestRun($tenant, $feed)->failure_reason)->toBeNull();
});
