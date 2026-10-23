<?php

declare(strict_types=1);

use App\Domain\Credits\Actions\GrantCredits;

/*
 * The mapping API: reading, editing, AI suggestions and approval, with roles, versions, credits
 * and isolation. Rule 6: no cost, markup or provider price in any response.
 */

it('shows the drafted mapping with its columns and the fields a column can fill', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant);

    $response = test()->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feed->id}/mapping")->assertOk();

    $response->assertJsonPath('mapping.status', 'draft')
        ->assertJsonPath('mapping.version', 1)
        ->assertJsonPath('mapping.source_locale', 'de')
        ->assertJsonPath('mapping.ai.available', false)
        ->assertJsonPath('mapping.ai.unavailable_reason', 'ai_disabled');
    $fields = collect($response->json('mapping.fields'))->keyBy('key');
    expect($fields['title@de']['source_column'])->toBe('Titel')
        ->and($fields['title@de']['samples'][0])->toBe('Wanderschuh aus Leder')
        ->and($fields['sku']['status'])->toBe('validated')
        ->and(collect($response->json('mapping.columns'))->firstWhere('name', 'Preis CHF')['protected'])->toBeTrue()
        ->and(collect($response->json('mapping.available_fields'))->pluck('key'))->toContain('title')->not->toContain('meta_title');
});

it('never shows a cost, markup or provider price, even with AI on (rule 6)', function (): void {
    $tenant = aiWorkspace();
    fakeAi();
    $feed = profiledFeed($tenant);

    $body = (string) test()->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feed->id}/mapping")->assertOk()->getContent();

    expect(json_decode($body, true)['mapping']['ai']['estimate_credits'])->toBeInt()->toBeGreaterThan(0)
        ->and($body)->not->toContain('cost')->not->toContain('markup')->not->toContain('usd')->not->toContain('millicredit');
});

it('answers 404 until the feed has a profile', function (): void {
    $tenant = aiWorkspace();
    $feedId = (int) createFeed(owner($tenant), ['name' => 'Empty'])->json('feed.id');

    test()->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feedId}/mapping")->assertNotFound()->assertJsonPath('error', 'no_profile');
});

it('lets a member read but not change the mapping', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant);
    $member = memberOf($tenant, 'member');

    test()->actingAs($member)->getJson("/api/v1/feeds/{$feed->id}/mapping")->assertOk();
    test()->actingAs($member)->putJson("/api/v1/feeds/{$feed->id}/mapping/fields", ['version' => 1, 'target' => 'title', 'locale' => 'de', 'column' => 'Beschreibung'])->assertForbidden();
    test()->actingAs($member)->postJson("/api/v1/feeds/{$feed->id}/mapping/suggest")->assertForbidden();
    test()->actingAs($member)->postJson("/api/v1/feeds/{$feed->id}/mapping/approve", ['version' => 1])->assertForbidden();
});

it('edits a field, stores a refused edit with its reason, and refuses a stale version', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant);
    $url = "/api/v1/feeds/{$feed->id}/mapping/fields";

    $edited = test()->actingAs(owner($tenant))->putJson($url, ['version' => 1, 'target' => 'title', 'locale' => 'de', 'column' => 'Beschreibung'])->assertOk();
    expect(collect($edited->json('mapping.fields'))->firstWhere('key', 'title@de')['origin'])->toBe('manual')
        ->and($edited->json('mapping.version'))->toBe(2);

    $refused = test()->actingAs(owner($tenant))->putJson($url, ['version' => 2, 'target' => 'description', 'locale' => 'de', 'column' => 'Preis CHF'])->assertOk();
    expect(collect($refused->json('mapping.fields'))->firstWhere('key', 'description@de')['status'])->toBe('rejected');

    test()->actingAs(owner($tenant))->putJson($url, ['version' => 1, 'target' => 'color', 'locale' => 'de'])
        ->assertStatus(409)->assertJsonPath('error', 'mapping_changed')->assertJsonPath('version', 3);
    test()->actingAs(owner($tenant))->putJson($url, ['version' => 3, 'target' => 'titre_fr', 'locale' => 'fr', 'column' => 'Titel'])
        ->assertUnprocessable();
    test()->actingAs(owner($tenant))->putJson($url, ['version' => 3, 'target' => 'title', 'locale' => 'de', 'column' => 'Titel', 'recipe' => ['sources' => [['column' => 'Titel']]]])
        ->assertUnprocessable();
});

it('queues an AI suggestion against the credits, and says why when it cannot', function (): void {
    $tenant = aiWorkspace();
    $fakes = fakeAi();
    $feed = profiledFeed($tenant);
    $url = "/api/v1/feeds/{$feed->id}/mapping/suggest";

    test()->actingAs(owner($tenant))->postJson($url)
        ->assertStatus(409)->assertJsonPath('error', 'insufficient_credits')->assertJsonPath('available_credits', 0);

    tenancy()->run($tenant->id, fn () => resolve(GrantCredits::class)->handle(50_000_000));
    $fakes['gemini']->push(mappingAnswers(['Farbe' => answer('color', 'de', 0.95)]));
    test()->actingAs(owner($tenant))->postJson($url, ['instructions' => 'Farbe is the colour.'])
        ->assertStatus(202)->assertJsonPath('mapping.ai.status', 'done');

    config(['ai.enabled' => false]);
    test()->actingAs(owner($tenant))->postJson($url)->assertStatus(409)->assertJsonPath('error', 'ai_unavailable')->assertJsonPath('reason', 'ai_disabled');
});

it('approves a complete mapping and explains what is missing otherwise', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant);

    test()->actingAs(owner($tenant))->putJson("/api/v1/feeds/{$feed->id}/mapping/fields", ['version' => 1, 'target' => 'sku'])->assertOk();
    test()->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feed->id}/mapping/approve", ['version' => 2])
        ->assertUnprocessable()->assertJsonPath('error', 'sku_missing');

    test()->actingAs(owner($tenant))->putJson("/api/v1/feeds/{$feed->id}/mapping/fields", ['version' => 2, 'target' => 'sku', 'column' => 'Artikelnummer'])->assertOk();
    test()->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feed->id}/mapping/approve", ['version' => 3])
        ->assertOk()->assertJsonPath('mapping.status', 'approved');
});

it("never reaches another workspace's feed", function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant);
    $intruder = aiWorkspace();

    test()->actingAs(owner($intruder))->getJson("/api/v1/feeds/{$feed->id}/mapping")->assertNotFound();
    test()->actingAs(owner($intruder))->putJson("/api/v1/feeds/{$feed->id}/mapping/fields", ['version' => 1, 'target' => 'sku'])->assertNotFound();
});

it('tells the feed page where the mapping stands', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant);
    $status = static fn (): ?string => test()->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feed->id}")->assertOk()->json('feed.mapping_status');

    expect($status())->toBeNull();
    test()->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feed->id}/mapping")->assertOk();
    expect($status())->toBe('draft');
    test()->actingAs(owner($tenant))->postJson("/api/v1/feeds/{$feed->id}/mapping/approve", ['version' => 1])->assertOk();
    expect($status())->toBe('approved');
});
