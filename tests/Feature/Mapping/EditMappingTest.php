<?php

declare(strict_types=1);

use App\Domain\Mapping\Actions\ApproveMapping;
use App\Domain\Mapping\Actions\EditMappingField;
use App\Domain\Mapping\Data\Recipe;
use App\Domain\Mapping\Exceptions\MappingChanged;
use App\Domain\Mapping\Exceptions\MappingIncomplete;
use App\Domain\Mapping\Models\Mapping;
use App\Domain\Mapping\Models\MappingField;
use App\Models\Tenant;

/*
 * A person's edits and the approval. Legacy D7: refine and save skipped the validation that generate
 * ran, so a person could put sizes in the colour or a price in the title.
 */

function edit(Tenant $tenant, Mapping $mapping, string $target, ?string $locale, ?string $column, ?Recipe $recipe = null): MappingField
{
    return tenancy()->run($tenant->id, static fn (): MappingField => resolve(EditMappingField::class)->handle($mapping->refresh(), $mapping->version, $target, $locale, $column, $recipe));
}

function approve(Tenant $tenant, Mapping $mapping): Mapping
{
    return tenancy()->run($tenant->id, static fn (): Mapping => resolve(ApproveMapping::class)->handle($mapping->refresh(), $mapping->version, owner($tenant)));
}

it('validates a person\'s edit like any suggestion (D7)', function (string $target, string $column, string $issue): void {
    $tenant = aiWorkspace();
    $mapping = draftOf($tenant, profiledFeed($tenant));

    $field = edit($tenant, $mapping, $target, 'de', $column);

    expect($field->strategy)->toBe('none')
        ->and($field->status)->toBe('rejected')
        ->and($field->candidate_column)->toBe($column)
        ->and($field->issues[0]['type'])->toBe($issue)
        ->and($field->origin)->toBe('manual');
})->with([
    'a price in the title' => ['title', 'Preis CHF', 'protected_column'],
    'the barcode in the description' => ['description', 'EAN', 'protected_column'],
    'sizes in the colour' => ['color', 'Grösse', 'values_do_not_fit'],
]);

it('keeps a person\'s valid edit and marks it as theirs', function (): void {
    $tenant = aiWorkspace();
    $mapping = draftOf($tenant, profiledFeed($tenant));

    $field = edit($tenant, $mapping, 'title', 'de', 'Beschreibung');

    expect($field->source_column)->toBe('Beschreibung')
        ->and($field->origin)->toBe('manual')
        ->and($mapping->refresh()->version)->toBe(2);
});

it('lets a person compose a field and clear one', function (): void {
    $tenant = aiWorkspace();
    $mapping = draftOf($tenant, profiledFeed($tenant));

    $composed = edit($tenant, $mapping, 'features', 'de', null, new Recipe([['column' => 'Material', 'label' => 'Material'], ['column' => 'Farbe', 'label' => 'Farbe']]));
    $cleared = edit($tenant, $mapping, 'color', 'de', null);

    expect($composed->status)->toBe('composed')
        ->and($cleared->strategy)->toBe('none')
        ->and($cleared->status)->toBe('cleared');
});

it('refuses fields, locales and columns that do not exist', function (string $target, ?string $locale, ?string $column): void {
    $tenant = aiWorkspace();
    $mapping = draftOf($tenant, profiledFeed($tenant));

    expect(fn (): MappingField => edit($tenant, $mapping, $target, $locale, $column))->toThrow(InvalidArgumentException::class);
    expect($mapping->refresh()->version)->toBe(1);
})->with([
    'unknown field' => ['titre_fr', 'fr', 'Titel'],
    'optimize-only field' => ['meta_title', 'de', 'Titel'],
    'localized field without a locale' => ['title', null, 'Titel'],
    'unknown locale' => ['title', 'xx', 'Titel'],
    'unknown column' => ['title', 'de', 'Nope'],
]);

it('refuses an edit made on a stale version', function (): void {
    $tenant = aiWorkspace();
    $mapping = draftOf($tenant, profiledFeed($tenant));
    $stale = $mapping->version;
    edit($tenant, $mapping, 'title', 'de', 'Beschreibung');

    expect(fn (): mixed => tenancy()->run($tenant->id, fn () => resolve(EditMappingField::class)->handle($mapping->refresh(), $stale, 'color', 'de', null, null)))
        ->toThrow(MappingChanged::class);
});

it('approves a mapping with a SKU and a text field, and an edit sends it back to draft', function (): void {
    $tenant = aiWorkspace();
    $mapping = draftOf($tenant, profiledFeed($tenant));

    $approved = approve($tenant, $mapping);
    expect($approved->status)->toBe('approved')->and($approved->approved_by)->toBe($tenant->owner_id);

    edit($tenant, $mapping, 'color', 'de', null);
    expect($mapping->refresh()->status)->toBe('draft')->and($mapping->approved_at)->toBeNull();
});

it('refuses to approve without the SKU', function (): void {
    $tenant = aiWorkspace();
    $mapping = draftOf($tenant, profiledFeed($tenant));
    edit($tenant, $mapping, 'sku', null, null);

    expect(fn (): Mapping => approve($tenant, $mapping))->toThrow(MappingIncomplete::class, 'SKU');
});

it("never shows or changes another workspace's mapping", function (): void {
    $tenant = aiWorkspace();
    $mapping = draftOf($tenant, profiledFeed($tenant));
    $other = aiWorkspace();

    expect(tenancy()->run($other->id, fn () => Mapping::query()->find($mapping->id)))->toBeNull()
        ->and(tenancy()->run($other->id, fn (): int => MappingField::query()->count()))->toBe(0);
});
