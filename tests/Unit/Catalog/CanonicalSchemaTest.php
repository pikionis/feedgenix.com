<?php

declare(strict_types=1);

use App\Domain\Catalog\Schema\CanonicalSchema;
use App\Domain\Catalog\Schema\FieldClass;
use App\Domain\Catalog\Schema\FieldRef;
use App\Domain\Catalog\Schema\TranslationMode;

/* The canonical schema of ADR 0003, as shipped, and the rules that keep a misconfiguration loud. */

function schema(): CanonicalSchema
{
    return CanonicalSchema::fromConfig();
}

it('ships exactly the golden fields of rule 1', function (): void {
    expect(schema()->goldenKeys())->toBe(['sku', 'parent_sku', 'gtin', 'mpn', 'price', 'sale_price', 'currency', 'stock_quantity', 'weight', 'dimensions']);
});

it('ships the composable fields, all translatable', function (): void {
    $composable = schema()->ofClass(FieldClass::Composable);

    expect(array_keys($composable))->toBe(['features', 'material', 'care_instructions', 'dimensions_text', 'other_attributes']);
    foreach ($composable as $field) {
        expect($field->isLocalized())->toBeTrue()->and($field->defaultMode)->toBe(TranslationMode::Translate);
    }
});

it('keeps sizes as they are by default, and translates the other translatable fields', function (): void {
    expect(schema()->get('size')->defaultMode)->toBe(TranslationMode::Keep)
        ->and(schema()->get('title')->defaultMode)->toBe(TranslationMode::Translate);
});

it('never offers optimize-only or derived fields as mapping targets', function (): void {
    $mappable = array_keys(schema()->mappable());

    expect($mappable)->not->toContain('short_description')
        ->not->toContain('meta_title')
        ->not->toContain('google_product_category')
        ->toContain('title')->toContain('sku');
});

it('refuses a misconfigured field loudly', function (array $field, string $message): void {
    config(['canonical_schema.fields' => ['x_field' => $field]]);

    expect(fn (): CanonicalSchema => CanonicalSchema::fromConfig())->toThrow(InvalidArgumentException::class, $message);
})->with([
    'unknown class' => [['class' => 'secret', 'kind' => 'text'], 'unknown class'],
    'unknown kind' => [['class' => 'golden', 'kind' => 'blob'], 'unknown kind'],
    'localized without a mode' => [['class' => 'translatable', 'kind' => 'text'], 'need a mode'],
    'golden with a mode' => [['class' => 'golden', 'kind' => 'code', 'mode' => 'translate'], 'need a mode'],
]);

it('refuses a key that is not lower snake case', function (): void {
    config(['canonical_schema.fields' => ['titre-FR' => ['class' => 'pass_through', 'kind' => 'text']]]);

    expect(fn (): CanonicalSchema => CanonicalSchema::fromConfig())->toThrow(InvalidArgumentException::class, 'snake case');
});

describe('field@locale', function (): void {
    it('names a localized field in a registry language, spelled as the registry spells it', function (string $ref, string $expected): void {
        expect(FieldRef::parse(schema(), $ref)->toString())->toBe($expected);
    })->with([
        ['title@de-ch', 'title@de-CH'],
        ['description@PT_br', 'description@pt-BR'],
        ['features@el', 'features@el'],
        ['sku', 'sku'],
        ['brand', 'brand'],
    ]);

    it('refuses a localized field without a locale, a shared field with one, an unknown locale or field', function (string $ref, string $message): void {
        expect(fn (): FieldRef => FieldRef::parse(schema(), $ref))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        ['title', 'stored per language'],
        ['sku@de', 'takes no locale'],
        ['gtin@en', 'takes no locale'],
        ['title@xx-YY', 'not a language'],
        ['titre_fr@fr', 'no canonical field'],
    ]);
});
