<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Export\Jobs\BuildExportJob;
use App\Domain\Export\Models\ExportArtifact;
use App\Domain\Feeds\Models\Feed;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * The exports API (ADR 0022): create an export and get its URLs at once, change, pause, build now,
 * new URLs, go back to an earlier build; and the reconciler building what changed.
 */

beforeEach(function (): void {
    Storage::fake('local');
});

function createExport(Tenant $tenant, int $feedId, array $body = []): TestResponse
{
    return test()->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feedId}/exports", [
        'name' => 'Google', 'preset' => 'google_merchant', 'dialect' => 'tsv', 'layout' => 'per_language', 'locales' => ['de', 'en'], ...$body,
    ]);
}

function pull(string $url): TestResponse
{
    return test()->get(parse_url($url, PHP_URL_PATH) ?: '/');
}

it('creates an export with its URLs, builds it at once, and lists it with the presets and languages', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    startTranslation($tenant, $feed);

    $export = createExport($tenant, $feed->id)->assertCreated()->json('export');

    expect(collect($export['urls'])->pluck('file')->all())->toBe(['products-de.tsv', 'products-en.tsv'])
        ->and($export['urls'][0]['url'])->toMatch('~/f/[A-Za-z0-9]{40}/products-de\.tsv$~')
        ->and($export['published'])->toMatchArray(['status' => 'published', 'rows' => 5])
        ->and($export['published']['report']['missing']['products-en.tsv']['link'])->toBe(5);
    pull($export['urls'][1]['url'])->assertOk();

    $list = test()->actingAs(memberOf($tenant, 'member'))->getJson("/api/v1/feeds/{$feed->id}/exports")->assertOk()->json();
    expect(collect($list['exports'])->pluck('name')->all())->toBe(['Google'])
        ->and(collect($list['presets'])->pluck('key')->all())->toBe(['standard', 'google_merchant'])
        ->and(collect($list['locales'])->pluck('code')->all())->toBe(['de', 'en']);
});

it('refuses a dialect, layout or language the export cannot have, a second export of the same name, and members', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);

    createExport($tenant, $feed->id, ['dialect' => 'csv'])->assertUnprocessable()->assertJsonPath('errors.export.0', 'Google Merchant Center is written as tsv.');
    createExport($tenant, $feed->id, ['layout' => 'wide'])->assertUnprocessable();
    createExport($tenant, $feed->id, ['locales' => ['ja']])->assertUnprocessable();
    createExport($tenant, $feed->id, ['locales' => []])->assertUnprocessable();
    createExport($tenant, $feed->id, ['preset' => 'nonsense'])->assertUnprocessable();
    createExport($tenant, $feed->id)->assertCreated();
    createExport($tenant, $feed->id)->assertUnprocessable()->assertJsonPath('errors.export.0', 'This feed already has an export with that name.');
    test()->actingAs(memberOf($tenant, 'member'))->postJson("/api/v1/feeds/{$feed->id}/exports", ['name' => 'X', 'preset' => 'standard', 'dialect' => 'csv', 'layout' => 'wide', 'locales' => ['de']])
        ->assertForbidden();
});

it('builds again when the files change, keeps serving while paused, and refuses a stale version', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $export = createExport($tenant, $feed->id, ['name' => 'All', 'preset' => 'standard', 'dialect' => 'csv', 'layout' => 'wide'])->json('export');
    $url = "/api/v1/exports/{$export['id']}";

    $changed = test()->actingAs(owner($tenant))->patchJson($url, ['version' => $export['version'], 'locales' => ['de']])->assertOk()->json('export');
    expect($changed['published']['id'])->not->toBe($export['published']['id'])
        ->and($changed['published']['files'][0]['rows'])->toBe(5);

    test()->actingAs(owner($tenant))->patchJson($url, ['version' => $export['version'], 'name' => 'Old'])->assertStatus(409)->assertJsonPath('error', 'export_changed');

    $paused = test()->actingAs(owner($tenant))->patchJson($url, ['version' => $changed['version'], 'status' => 'paused', 'locales' => ['de', 'en']])->assertOk()->json('export');
    expect($paused['status'])->toBe('paused')
        ->and($paused['published']['id'])->toBe($changed['published']['id']);
    pull($paused['urls'][0]['url'])->assertOk();
});

it('builds now on request, publishing a shrunk build only when told to', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant, manyProductsCsv(60));
    approveMapping($tenant, $feed);
    $export = createExport($tenant, $feed->id, ['name' => 'All', 'preset' => 'standard', 'dialect' => 'csv', 'layout' => 'wide', 'locales' => ['de']])->json('export');
    tenancy()->run($tenant->id, fn () => Product::query()->where('feed_id', $feed->id)->where('sku', '>', 'AO-0020')->update(['status' => 'disappeared']));
    $url = "/api/v1/exports/{$export['id']}/build";

    $held = test()->actingAs(owner($tenant))->postJson($url)->assertStatus(202)->json('export');
    expect($held['latest'])->toMatchArray(['status' => 'rejected', 'failure_reason' => 'shrink'])
        ->and($held['published']['rows'])->toBe(60);

    $forced = test()->actingAs(owner($tenant))->postJson($url, ['allow_shrink' => true])->assertStatus(202)->json('export');
    expect($forced['published']['rows'])->toBe(20);
});

it('gives new URLs that work at once while the old ones still work for a while', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $export = createExport($tenant, $feed->id)->json('export');

    $rotated = test()->actingAs(owner($tenant))->postJson("/api/v1/exports/{$export['id']}/token")->assertOk()->json('export');

    expect($rotated['urls'][0]['url'])->not->toBe($export['urls'][0]['url'])
        ->and($rotated['previous_urls_until'])->not->toBeNull();
    pull($rotated['urls'][0]['url'])->assertOk();
    pull($export['urls'][0]['url'])->assertOk();
});

it('goes back to an earlier build at once, and only to one whose files are kept', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $export = createExport($tenant, $feed->id, ['name' => 'All', 'preset' => 'standard', 'dialect' => 'csv', 'layout' => 'wide'])->json('export');
    $first = $export['published']['id'];
    startTranslation($tenant, $feed);
    $second = test()->actingAs(owner($tenant))->postJson("/api/v1/exports/{$export['id']}/build")->json('export');
    expect($second['published']['id'])->not->toBe($first)
        ->and(collect($second['earlier'])->pluck('id')->all())->toBe([$first]);

    $back = test()->actingAs(owner($tenant))->postJson("/api/v1/exports/{$export['id']}/builds/{$first}/publish")->assertOk()->json('export');
    expect($back['published']['id'])->toBe($first);
    $sha = tenancy()->run($tenant->id, fn (): ?string => ExportArtifact::query()->find($first)?->files[0]['sha256']);
    pull($back['urls'][0]['url'])->assertOk()->assertHeader('ETag', "\"{$sha}\"");

    tenancy()->run($tenant->id, fn () => ExportArtifact::query()->whereKey($second['published']['id'])->update(['files_deleted' => true]));
    test()->actingAs(owner($tenant))->postJson("/api/v1/exports/{$export['id']}/builds/{$second['published']['id']}/publish")->assertUnprocessable();
});

it('deletes an export, and its URLs stop at once', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $export = createExport($tenant, $feed->id)->json('export');

    test()->actingAs(owner($tenant))->deleteJson("/api/v1/exports/{$export['id']}")->assertNoContent();

    pull($export['urls'][0]['url'])->assertNotFound();
});

it("never reaches another workspace's exports", function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $export = createExport($tenant, $feed->id)->json('export');
    $other = aiWorkspace();

    test()->actingAs(owner($other))->getJson("/api/v1/feeds/{$feed->id}/exports")->assertNotFound();
    test()->actingAs(owner($other))->patchJson("/api/v1/exports/{$export['id']}", ['version' => 1, 'name' => 'Mine'])->assertNotFound();
    test()->actingAs(owner($other))->postJson("/api/v1/exports/{$export['id']}/token")->assertNotFound();
});

it('builds what changed once nothing reads or translates the feed, and nothing else', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $export = createExport($tenant, $feed->id)->json('export');
    $reconcile = fn () => Artisan::call('ops:reconcile', [], new BufferedOutput);

    // Nothing changed since the first build.
    Queue::fake([BuildExportJob::class]);
    $reconcile();
    Queue::assertNothingPushed();

    // A translation runs: no build while it works.
    $this->travel(1)->minutes();
    $tenant->forceFill(['limits_override' => ['worker_slots' => 0]])->save();
    startTranslation($tenant, $feed);
    expect(latestRun($tenant, $feed)->status)->toBe('running');
    $reconcile();
    Queue::assertNothingPushed();

    // A build that started meanwhile (queued before the run) saw only part of the translations.
    $this->travel(1)->minutes();
    tenancy()->run($tenant->id, fn () => ExportArtifact::query()->forceCreate(['profile_id' => $export['id'], 'status' => 'unchanged']));
    $this->travel(1)->minutes();

    // It ends (this pass hands the units out and they finish); the next pass builds the export.
    $tenant->forceFill(['limits_override' => null])->save();
    $reconcile();
    expect(latestRun($tenant, $feed)->status)->toBe('done');
    Queue::assertNothingPushed();
    $reconcile();
    Queue::assertPushed(BuildExportJob::class, fn (BuildExportJob $job): bool => $job->profileId === $export['id']);
});

it("counts a new file, a finished translation and a person's word on a text as changes to export", function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $changedAt = function () use ($tenant, $feed): ?string {
        $this->travel(1)->seconds();

        return tenancy()->run($tenant->id, fn () => Feed::query()->find($feed->id)?->content_changed_at?->toIso8601String());
    };
    $synced = $changedAt();
    expect($synced)->not->toBeNull();

    startTranslation($tenant, $feed);
    $translated = $changedAt();
    expect($translated)->not->toBe($synced);

    $title = reviewText(reviewList($tenant, $feed->id)->json('data.0'), 'title');
    test()->actingAs(owner($tenant))->putJson('/api/v1/products/'.productsOf($tenant, $feed)['AO-1001']->id.'/texts/title/en', [
        'value' => 'Leather hiking boot', 'source_locale' => 'de', 'source_hash' => $title['source']['hash'],
    ])->assertOk();
    $reviewed = $changedAt();
    expect($reviewed)->not->toBe($translated);

    uploadFeed(owner($tenant), $feed->id, str_replace('189.90', '179.90', ALPINE_SHOP_CSV), 'export.csv')->assertStatus(202);
    expect($changedAt())->not->toBe($reviewed);
});

it('marks a build killed mid-way as failed, so the next one can run', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $export = createExport($tenant, $feed->id)->json('export');
    $stuck = tenancy()->run($tenant->id, fn () => ExportArtifact::query()->forceCreate(['profile_id' => $export['id'], 'status' => 'building']));

    $this->travel(4)->hours();
    Artisan::call('ops:reconcile', [], new BufferedOutput);

    expect(tenancy()->run($tenant->id, fn () => $stuck->fresh()?->status))->toBe('failed');
});
