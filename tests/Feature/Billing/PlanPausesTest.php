<?php

declare(strict_types=1);

use App\Domain\Catalog\Events\CatalogSynced;
use App\Domain\Export\FeedUrls;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Tenancy\Models\TenantLanguage;
use App\Domain\Translation\Models\WorkUnit;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;

/*
 * Plan pauses (ADR 0024, ported from PlanLimitsApplier): over the limits after a downgrade or a
 * lapsed plan, feeds and languages pause and nothing is deleted; their waiting AI work is held and
 * resumes with them; a paused feed's URLs keep serving. The legacy app paused feeds only.
 */

beforeEach(function (): void {
    Storage::fake('local');
});

/** @return array<string, bool> feed name => paused */
function feedPauses(Tenant $tenant): array
{
    return tenancy()->run($tenant->id, fn (): array => Feed::query()->orderBy('id')->get()->mapWithKeys(fn (Feed $feed): array => [$feed->name => $feed->plan_paused_at !== null])->all());
}

/** @return array<string, bool> language => paused */
function languagePauses(Tenant $tenant): array
{
    return tenancy()->run($tenant->id, fn (): array => TenantLanguage::query()->orderBy('language_code')->get()->mapWithKeys(fn (TenantLanguage $language): array => [$language->language_code => $language->plan_paused_at !== null])->all());
}

/** A Pro workspace with three feeds, changed in turn, and three languages, added in turn. */
function busyProWorkspace(): array
{
    $tenant = aiWorkspace();
    onPro($tenant);
    $feeds = [];
    foreach (['Oldest', 'Middle', 'Newest'] as $name) {
        test()->travel(1)->minutes();
        $feed = profiledFeed($tenant);
        tenancy()->run($tenant->id, fn () => $feed->forceFill(['name' => $name])->save());
        $feeds[$name] = $feed->fresh();
    }
    foreach (['en', 'fr', 'it'] as $code) {
        test()->travel(1)->minutes();
        addLanguage($tenant, $code);
    }

    return [$tenant, $feeds];
}

it('pauses what is over a lower plan, deletes nothing, and resumes it all on the way back', function (): void {
    [$tenant] = busyProWorkspace();

    $printed = changePlan($tenant, 'free');

    expect(feedPauses($tenant))->toBe(['Oldest' => true, 'Middle' => true, 'Newest' => false])
        ->and(languagePauses($tenant))->toBe(['en' => false, 'fr' => true, 'it' => true])
        ->and($printed)->toContain('Paused 2 feed(s) and 2 language(s)')
        ->and($tenant->fresh()?->applied_plan)->toBe('free');

    // Pausing again changes nothing: a pause does not reorder the feeds.
    changePlan($tenant, 'free');
    expect(feedPauses($tenant))->toBe(['Oldest' => true, 'Middle' => true, 'Newest' => false]);

    changePlan($tenant, 'pro');
    expect(array_filter(feedPauses($tenant)))->toBe([])
        ->and(array_filter(languagePauses($tenant)))->toBe([]);

    // Down again: the same feeds pause (a pause never made them look recently changed).
    $this->travel(1)->minutes();
    changePlan($tenant, 'free');
    expect(feedPauses($tenant))->toBe(['Oldest' => true, 'Middle' => true, 'Newest' => false]);
});

it('starts no automatic translation for a feed the plan paused', function (): void {
    [$tenant, $feed, $fakes] = translatingWorkspace(['en']);
    $plan = test()->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feed->id}/translation-plan")->json('translation_plan');
    test()->actingAs(owner($tenant))->putJson("/api/v1/feeds/{$feed->id}/translation-plan", ['version' => $plan['version'], 'fields' => [], 'target_locales' => ['en'], 'auto_translate' => true])->assertOk();
    tenancy()->run($tenant->id, fn () => Feed::query()->whereKey($feed->id)->update(['plan_paused_at' => now()]));

    // A file read just before the pause finishes reading after it.
    tenancy()->run($tenant->id, fn () => event(new CatalogSynced(latestRun($tenant, $feed)->id, $feed->id)));

    expect($fakes['gemini']->calls)->toBe([])
        ->and(latestRun($tenant, $feed)->stage)->toBe('plan');
});

it('refuses new files and AI work for a paused feed with the reason, and leaves the rest open', function (): void {
    [$tenant, $feeds] = busyProWorkspace();
    changePlan($tenant, 'free');
    $id = $feeds['Oldest']->id;
    $owner = owner($tenant);

    $refused = test()->actingAs($owner)->postJson("/api/v1/feeds/{$id}/uploads", ['filename' => 'export.csv', 'size' => 100])
        ->assertStatus(402)->assertJsonPath('error', 'feed_paused')->assertJsonPath('upgrade_to', 'pro');
    expect($refused->json('message'))->toContain('This feed is paused', 'Its feed URLs keep working', 'Upgrade to Pro');
    test()->actingAs($owner)->postJson("/api/v1/feeds/{$id}/fetch")->assertStatus(402);
    test()->actingAs($owner)->postJson("/api/v1/feeds/{$id}/mapping/suggest")->assertStatus(402);
    test()->actingAs($owner)->postJson("/api/v1/feeds/{$id}/translation-plan/start", ['version' => 1])->assertStatus(402);
    test()->actingAs($owner)->postJson("/api/v1/feeds/{$id}/catalog/sync")->assertStatus(402);
    $import = tenancy()->run($tenant->id, fn () => $feeds['Oldest']->imports()->latest('id')->value('id'));
    test()->actingAs($owner)->postJson("/api/v1/imports/{$import}/reread", ['delimiter' => ','])->assertStatus(402);

    // Looking and the mapping stay open; the newest feed works as before.
    expect(test()->actingAs($owner)->getJson("/api/v1/feeds/{$id}")->assertOk()->json('feed.plan_paused'))->toBeTrue();
    test()->actingAs($owner)->getJson("/api/v1/feeds/{$id}/mapping")->assertOk();
    test()->actingAs($owner)->postJson("/api/v1/feeds/{$feeds['Newest']->id}/uploads", ['filename' => 'export.csv', 'size' => 100])->assertCreated();
});

it('keeps serving the last build of a paused feed', function (): void {
    [$tenant, $feeds] = busyProWorkspace();
    approveMapping($tenant, $feeds['Oldest']);
    $profile = exportProfile($tenant, $feeds['Oldest'], ['locales' => ['de']]);
    $token = tenancy()->run($tenant->id, fn (): string => FeedUrls::issue($profile));
    buildExport($tenant, $profile);

    changePlan($tenant, 'free');

    test()->get("/f/{$token}/products.csv")->assertOk();
});

it('holds the waiting work of what paused, and finishes it once it is back', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en', 'fr']);
    onPro($tenant);
    $tenant->forceFill(['limits_override' => ['worker_slots' => 0]])->save();
    startTranslation($tenant, $feed);
    expect(unitStatuses($tenant))->toHaveKey('pending');

    // Free: one language. French was added last, so French work waits.
    $tenant->forceFill(['plan' => 'free', 'billing_interval' => null, 'plan_started_at' => null, 'plan_ends_at' => null])->save();
    Artisan::call('plans:apply', [], new BufferedOutput);

    $held = tenancy()->run($tenant->id, fn () => WorkUnit::query()->where('status', 'held')->distinct()->pluck('hold_reason', 'target_locale')->all());
    expect($held)->toBe(['fr' => 'language_paused'])
        ->and(collect(latestRun($tenant, $feed)->warnings)->pluck('code')->all())->not->toContain('held_feed_paused');

    $tenant->forceFill(['limits_override' => null])->save();
    onPro($tenant);
    Artisan::call('plans:apply', [], new BufferedOutput);
    Artisan::call('ops:reconcile', [], new BufferedOutput);

    expect(unitStatuses($tenant))->toBe(['done' => array_sum(unitStatuses($tenant))])
        ->and(latestRun($tenant, $feed)->status)->toBe('done');
});

it('translates nothing new into a paused language, and keeps it in the plan', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en', 'fr']);
    $plan = test()->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feed->id}/translation-plan")->json('translation_plan');
    test()->actingAs(owner($tenant))->putJson("/api/v1/feeds/{$feed->id}/translation-plan", ['version' => $plan['version'], 'fields' => [], 'target_locales' => ['en', 'fr']])->assertOk();
    tenancy()->run($tenant->id, fn () => TenantLanguage::query()->where('language_code', 'fr')->update(['plan_paused_at' => now()]));

    $plan = startTranslation($tenant, $feed);

    expect($plan['target_locales'])->toBe(['en', 'fr'])
        ->and(collect($plan['workspace_locales'])->firstWhere('code', 'fr')['paused'])->toBeTrue()
        ->and(tenancy()->run($tenant->id, fn () => WorkUnit::query()->distinct()->pluck('target_locale')->all()))->toBe(['en'])
        ->and(collect($plan['work'])->pluck('target_locale')->unique()->values()->all())->toBe(['en']);
});

it('pauses a plan that lapsed by date at the next hourly pass, once', function (): void {
    [$tenant] = busyProWorkspace();
    Artisan::call('plans:apply', [], new BufferedOutput);
    expect(array_filter(feedPauses($tenant)))->toBe([]);

    $this->travel(35)->days();
    $output = new BufferedOutput;
    Artisan::call('plans:apply', [], $output);
    expect($output->fetch())->toContain('Applied the plan of 1 workspace(s).')
        ->and(feedPauses($tenant))->toBe(['Oldest' => true, 'Middle' => true, 'Newest' => false]);

    $again = new BufferedOutput;
    Artisan::call('plans:apply', [], $again);
    expect($again->fetch())->toContain('Applied the plan of 0 workspace(s).');
});

it('resumes a paused feed when another is deleted, and paused languages when others are removed', function (): void {
    [$tenant, $feeds] = busyProWorkspace();
    changePlan($tenant, 'free');

    test()->actingAs(owner($tenant))->deleteJson("/api/v1/feeds/{$feeds['Newest']->id}")->assertNoContent();
    expect(feedPauses($tenant))->toBe(['Oldest' => true, 'Middle' => false]);

    $workspace = test()->actingAs(owner($tenant))->putJson('/api/v1/workspace/languages', ['languages' => ['it']])->assertOk()->json();
    expect(languagePauses($tenant))->toBe(['it' => false])
        ->and($workspace['paused_languages'])->toBe([]);
});
