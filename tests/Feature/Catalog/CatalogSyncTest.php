<?php

declare(strict_types=1);

use App\Domain\Catalog\Models\Product;
use App\Domain\Catalog\Models\ProductText;
use App\Domain\Feeds\Models\PipelineRun;

/*
 * Reading a feed into the catalogue through its approved mapping. Legacy defects: D9 (identical
 * texts in two languages deleted), D10 (an unguarded disappear step), old values kept for ever,
 * "0" dropped, placeholders translated.
 */

it('reads the feed into the catalogue on approval, then waits for the translation plan', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant);

    approveMapping($tenant, $feed);

    $run = latestRun($tenant, $feed);
    expect($run->stage)->toBe('plan')
        ->and($run->status)->toBe('awaiting_decision')
        ->and($run->counts)->toMatchArray(['rows' => 5, 'new' => 5, 'changed' => 0, 'no_sku' => 0]);
    $products = productsOf($tenant, $feed);
    expect(array_keys($products))->toEqualCanonicalizing(['AO-1001', 'AO-1002', 'AO-1003', 'AO-1004', 'AO-1005'])
        ->and($products['AO-1003']->source_values)->toEqual([
            'gtin' => '9780201379624', 'image_link' => 'https://img.example/ao-1003.jpg', 'price' => '89.50', 'stock_quantity' => '0',
        ])
        ->and(textsOf($tenant, $products['AO-1001'])['title@de']->value)->toBe('Wanderschuh aus Leder')
        ->and(textsOf($tenant, $products['AO-1001'])['title@de']->role)->toBe('source');
});

it('keeps golden values byte for byte: leading zeros, spaces, "0", "n/a" and any script', function (): void {
    $tenant = aiWorkspace();
    $csv = "Artikelnummer;Titel;Preis CHF;Lagerbestand;Verfügbarkeit;Beschreibung\n"
        ."0012345; Zelt für zwei ;  19.90 ;0;in stock;Ein Zelt für die Berge und die Täler\n"
        ."0012346;Ø 30 cm Topf;24.00;12;n/a;n/a\n";
    $feed = profiledFeed($tenant, $csv);

    approveMapping($tenant, $feed);

    $products = productsOf($tenant, $feed);
    expect($products)->toHaveKeys(['0012345', '0012346'])
        ->and($products['0012345']->source_values)->toEqual(['availability' => 'in stock', 'price' => '  19.90 ', 'stock_quantity' => '0'])
        ->and($products['0012346']->source_values)->toEqual(['availability' => 'n/a', 'price' => '24.00', 'stock_quantity' => '12'])
        ->and(textsOf($tenant, $products['0012346']))->not->toHaveKey('description@de')
        ->and(textsOf($tenant, $products['0012346'])['title@de']->value)->toBe('Ø 30 cm Topf');
});

it('makes translations of a changed source stale, and a person\'s translation stale but untouched', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant);
    approveMapping($tenant, $feed);
    $products = productsOf($tenant, $feed);
    translateTitle($tenant, $products['AO-1001'], 'de', 'Leather hiking boot');
    translateTitle($tenant, $products['AO-1002'], 'de', 'Rain jacket, hooded', 'manual');

    $changed = str_replace(['Wanderschuh aus Leder', 'Regenjacke mit Kapuze'], ['Wanderschuh aus Wildleder', 'Regenjacke mit Schirm'], ALPINE_SHOP_CSV);
    uploadFeed(owner($tenant), $feed->id, $changed, 'export-2.csv')->assertStatus(202);

    $run = latestRun($tenant, $feed);
    $first = textsOf($tenant, $products['AO-1001']);
    $second = textsOf($tenant, $products['AO-1002']);
    expect($run->counts)->toMatchArray(['changed' => 2, 'unchanged' => 3, 'stale_translations' => 2])
        ->and($first['title@de']->value)->toBe('Wanderschuh aus Wildleder')
        ->and($first['title@en']->status)->toBe('stale')
        ->and($second['title@en']->status)->toBe('stale')
        ->and($second['title@en']->value)->toBe('Rain jacket, hooded')
        ->and($second['title@en']->origin)->toBe('manual');
});

it('removes a value the feed blanked, instead of keeping it for ever', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant);
    approveMapping($tenant, $feed);
    $product = productsOf($tenant, $feed)['AO-1001'];
    translateTitle($tenant, $product, 'de', 'Leather hiking boot');

    $blanked = str_replace(['Wanderschuh aus Leder', ';189.90;'], ['', ';;'], ALPINE_SHOP_CSV);
    uploadFeed(owner($tenant), $feed->id, $blanked, 'export-2.csv')->assertStatus(202);

    $texts = textsOf($tenant, $product);
    expect($texts)->not->toHaveKey('title@de')
        ->and($texts['title@en']->status)->toBe('stale')
        ->and(productsOf($tenant, $feed)['AO-1001']->source_values)->not->toHaveKey('price');
});

it('keeps both languages when they hold the same text (legacy D9 deleted them)', function (): void {
    $tenant = aiWorkspace();
    $csv = "sku;title_de;title_fr;price\nM-1;Gore-Tex Pro;Gore-Tex Pro;99\nM-2;Merino 200;Merino 200;49\n";
    $feed = profiledFeed($tenant, $csv);

    approveMapping($tenant, $feed);

    $texts = textsOf($tenant, productsOf($tenant, $feed)['M-1']);
    expect($texts['title@de']->value)->toBe('Gore-Tex Pro')
        ->and($texts['title@fr']->value)->toBe('Gore-Tex Pro');
});

it('marks products the file no longer lists as gone', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant);
    approveMapping($tenant, $feed);

    $fewer = implode("\n", array_slice(explode("\n", ALPINE_SHOP_CSV), 0, 4))."\n";
    uploadFeed(owner($tenant), $feed->id, $fewer, 'export-2.csv')->assertStatus(202);

    $products = productsOf($tenant, $feed);
    expect(latestRun($tenant, $feed)->counts['disappeared'])->toBe(2)
        ->and($products['AO-1004']->status)->toBe('disappeared')
        ->and($products['AO-1004']->disappeared_at)->not->toBeNull()
        ->and($products['AO-1001']->status)->toBe('active');
});

it('refuses to remove more than 30% of a catalogue of over 50 at once (legacy D10)', function (): void {
    $tenant = aiWorkspace();
    $rows = static fn (int $count): string => "Artikelnummer;Titel;Preis CHF\n".implode("\n", array_map(static fn (int $i): string => "P-{$i};Ein Produkt mit der Nummer {$i} für den Test;".(10 + $i).'.00', range(1, $count)))."\n";
    $feed = profiledFeed($tenant, $rows(60));
    approveMapping($tenant, $feed);

    uploadFeed(owner($tenant), $feed->id, $rows(30), 'export-2.csv')->assertStatus(202);

    $run = latestRun($tenant, $feed);
    $active = array_filter(productsOf($tenant, $feed), static fn (Product $product): bool => $product->status === 'active');
    expect($active)->toHaveCount(60)
        ->and($run->counts['disappeared'])->toBe(0)
        ->and($run->warnings[0]['code'])->toBe('mass_disappearance')
        ->and($run->warnings[0]['count'])->toBe(30);
});

it('counts rows without a SKU and repeated SKUs, the last row winning', function (): void {
    $tenant = aiWorkspace();
    $csv = "Artikelnummer;Titel;Preis CHF\nA-1;Erster Titel für das Produkt;10.00\n;Ohne Nummer;11.00\nA-1;Zweiter Titel für das Produkt;12.00\nA-2;Ein anderes Produkt für den Test;13.00\n";
    $feed = profiledFeed($tenant, $csv);

    approveMapping($tenant, $feed);

    $products = productsOf($tenant, $feed);
    expect(latestRun($tenant, $feed)->counts)->toMatchArray(['rows' => 4, 'no_sku' => 1, 'duplicates' => 1, 'new' => 2])
        ->and($products['A-1']->source_values['price'])->toBe('12.00')
        ->and(textsOf($tenant, $products['A-1'])['title@de']->value)->toBe('Zweiter Titel für das Produkt');
});

it('stores a composed text as composed', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant);
    $version = (int) test()->actingAs(owner($tenant))->getJson("/api/v1/feeds/{$feed->id}/mapping")->json('mapping.version');
    test()->actingAs(owner($tenant))->putJson("/api/v1/feeds/{$feed->id}/mapping/fields", [
        'version' => $version, 'target' => 'features', 'locale' => 'de',
        'recipe' => ['sources' => [['column' => 'Material', 'label' => 'Material'], ['column' => 'Farbe', 'label' => 'Farbe']]],
    ])->assertOk();

    approveMapping($tenant, $feed);

    $features = textsOf($tenant, productsOf($tenant, $feed)['AO-1001'])['features@de'];
    expect($features->value)->toBe("Material: Leder\nFarbe: Braun")->and($features->origin)->toBe('composed');
});

it('supersedes a run waiting for a decision when a new file arrives, and reads the new file', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant);
    approveMapping($tenant, $feed);
    $first = latestRun($tenant, $feed);

    uploadFeed(owner($tenant), $feed->id, str_replace('AO-1005', 'AO-1006', ALPINE_SHOP_CSV), 'export-2.csv')->assertStatus(202);

    $second = latestRun($tenant, $feed);
    expect($first->refresh()->failure_reason)->toBe('superseded')
        ->and($second->id)->not->toBe($first->id)
        ->and($second->status)->toBe('awaiting_decision')
        ->and(array_keys(productsOf($tenant, $feed)))->toContain('AO-1006');
});

it('reads nothing before the mapping is approved, even when a new file arrives', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant);
    draftOf($tenant, $feed);
    uploadFeed(owner($tenant), $feed->id, str_replace('AO-1005', 'AO-1006', ALPINE_SHOP_CSV), 'export-2.csv')->assertStatus(202);

    expect(productsOf($tenant, $feed))->toBe([])
        ->and(tenancy()->run($tenant->id, fn (): int => PipelineRun::query()->count()))->toBe(0);
});

it("keeps one workspace's catalogue invisible to another", function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant);
    approveMapping($tenant, $feed);
    $other = aiWorkspace();

    expect(tenancy()->run($other->id, fn (): int => Product::query()->count() + ProductText::query()->count()))->toBe(0);
});
