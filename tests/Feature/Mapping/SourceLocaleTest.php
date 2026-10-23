<?php

declare(strict_types=1);

/*
 * The feed's language, said by a person when short product names do not give it away: the text
 * fields are filed under it.
 */

const TOOLS_CSV = <<<'CSV'
SKU;Title;EAN;Price;Stock;Colour;Image
HT-100;Claw hammer;4006381333931;12.9;40;Black;https://img.example/ht-100.jpg
HT-101;Rubber mallet;5901234123457;9.5;12;Black;https://img.example/ht-101.jpg
SD-200;Screwdriver set;96385074;24;0;;https://img.example/sd-200.jpg
TP-300;Measuring tape 5 m;4006381333948;7.25;85;Yellow;https://img.example/tp-300.jpg
CSV;

it('cannot place text fields while the feed language is unknown, and files them once it is said', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant, TOOLS_CSV);
    $mapping = draftOf($tenant, $feed);
    expect($mapping->source_locale)->toBeNull()
        ->and(array_keys(columnsOf($tenant, $mapping)))->not->toContain('title@en');

    $response = test()->actingAs(owner($tenant))->patchJson("/api/v1/feeds/{$feed->id}/mapping", ['version' => 1, 'source_locale' => 'EN'])->assertOk();

    $fields = collect($response->json('mapping.fields'))->keyBy('key');
    expect($response->json('mapping.source_locale'))->toBe('en')
        ->and($fields['title@en']['source_column'])->toBe('Title')
        ->and($fields['color@en']['source_column'])->toBe('Colour')
        ->and($fields['sku']['source_column'])->toBe('SKU');
});

it('moves the fields filed under the old language, but never a person\'s own', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant);
    $url = "/api/v1/feeds/{$feed->id}/mapping";
    test()->actingAs(owner($tenant))->putJson("{$url}/fields", ['version' => 1, 'target' => 'warranty', 'locale' => 'de', 'column' => 'Pflegehinweis'])->assertOk();

    $fields = collect(test()->actingAs(owner($tenant))->patchJson($url, ['version' => 2, 'source_locale' => 'de-CH'])->assertOk()->json('mapping.fields'))->keyBy('key');

    expect($fields->keys()->all())->toContain('title@de-CH')->toContain('description@de-CH')->not->toContain('title@de')
        ->and($fields['warranty@de']['origin'])->toBe('manual');
});

it('refuses a language outside the registry, and a stale version', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant);
    $url = "/api/v1/feeds/{$feed->id}/mapping";

    test()->actingAs(owner($tenant))->patchJson($url, ['version' => 1, 'source_locale' => 'xx'])->assertUnprocessable();
    test()->actingAs(owner($tenant))->patchJson($url, ['version' => 7, 'source_locale' => 'en'])->assertStatus(409);
    test()->actingAs(memberOf($tenant, 'member'))->patchJson($url, ['version' => 1, 'source_locale' => 'en'])->assertForbidden();
});
