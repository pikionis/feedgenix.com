<?php

declare(strict_types=1);

use App\Domain\Feeds\Models\Feed;
use App\Domain\Mapping\Actions\DraftMapping;
use App\Domain\Mapping\Models\Mapping;

/*
 * The free first draft (no AI) and the column-signature key. Legacy D6: a mapping was reused by
 * file id, so a re-upload with other columns kept a mapping that no longer matched.
 */

it('drafts a mapping from the column names and value kinds alone', function (): void {
    $tenant = aiWorkspace();
    $mapping = draftOf($tenant, profiledFeed($tenant));

    expect($mapping->source_locale)->toBe('de')
        ->and($mapping->status)->toBe('draft')
        ->and(columnsOf($tenant, $mapping))->toBe([
            'care_instructions@de' => 'Pflegehinweis',
            'color@de' => 'Farbe',
            'description@de' => 'Beschreibung',
            'gtin' => 'EAN',
            'image_link' => 'Bild URL',
            'material@de' => 'Material',
            'price' => 'Preis CHF',
            'size@de' => 'Grösse',
            'sku' => 'Artikelnummer',
            'stock_quantity' => 'Lagerbestand',
            'title@de' => 'Titel',
        ]);
    expect(fieldsOf($tenant, $mapping)['gtin']->status)->toBe('validated')
        ->and(fieldsOf($tenant, $mapping)['sku']->origin)->toBe('heuristic');
});

it('keeps one mapping per column signature: the same columns reuse it, other columns get their own (D6)', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant);
    $first = draftOf($tenant, $feed);

    uploadFeed(owner($tenant), $feed->id, str_replace('AO-1005', 'AO-1006', ALPINE_SHOP_CSV), 'export-2.csv')->assertStatus(202);
    $same = draftOf($tenant, $feed);

    $withAColumn = preg_replace('/^(.*)$/m', '$1;x', ALPINE_SHOP_CSV);
    uploadFeed(owner($tenant), $feed->id, (string) $withAColumn, 'export-3.csv')->assertStatus(202);
    $other = draftOf($tenant, $feed);

    expect($same->id)->toBe($first->id)
        ->and($other->id)->not->toBe($first->id)
        ->and($other->column_signature)->not->toBe($first->column_signature)
        ->and(tenancy()->run($tenant->id, fn (): int => Mapping::query()->count()))->toBe(2);
});

it('drafts nothing before a feed has a profile', function (): void {
    $tenant = aiWorkspace();
    $feedId = (int) createFeed(owner($tenant), ['name' => 'Empty'])->json('feed.id');
    $feed = tenancy()->run($tenant->id, fn () => Feed::query()->findOrFail($feedId));

    expect(tenancy()->run($tenant->id, fn () => resolve(DraftMapping::class)->current($feed)))->toBeNull();
});

it('drafts the same mapping once, however often it is asked for', function (): void {
    $tenant = aiWorkspace();
    $feed = profiledFeed($tenant);

    $ids = array_map(static fn (): int => draftOf($tenant, $feed)->id, range(1, 3));

    expect(array_unique($ids))->toHaveCount(1);
});
