<?php

declare(strict_types=1);

use App\Domain\AI\ValueSchema;

const FIELD_SCHEMA = [
    'type' => 'object',
    'properties' => [
        'field' => ['type' => ['string', 'null'], 'enum' => ['title', 'brand', null]],
        'confidence' => ['type' => 'number'],
        'parts' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 5], 'maxItems' => 2],
    ],
    'required' => ['field', 'confidence'],
    'additionalProperties' => false,
];

it('accepts an answer that matches its schema', function (mixed $value): void {
    expect(ValueSchema::accepts(FIELD_SCHEMA, $value))->toBeTrue();
})->with([
    'complete' => [['field' => 'title', 'confidence' => 0.9, 'parts' => ['a', 'b']]],
    'null where allowed' => [['field' => null, 'confidence' => 1]],
    'empty list' => [['field' => 'brand', 'confidence' => 0, 'parts' => []]],
]);

it('rejects an answer that does not', function (mixed $value): void {
    expect(ValueSchema::accepts(FIELD_SCHEMA, $value))->toBeFalse();
})->with([
    'not an object' => ['title'],
    'a list instead of an object' => [['title', 0.9]],
    'missing required' => [['field' => 'title']],
    'extra property' => [['field' => 'title', 'confidence' => 1, 'sku' => '123']],
    'outside the enum' => [['field' => 'gtin', 'confidence' => 1]],
    'wrong type' => [['field' => 'title', 'confidence' => '0.9']],
    'too long' => [['field' => 'title', 'confidence' => 1, 'parts' => ['abcdef']]],
    'too many' => [['field' => 'title', 'confidence' => 1, 'parts' => ['a', 'b', 'c']]],
    'wrong item type' => [['field' => 'title', 'confidence' => 1, 'parts' => [1]]],
]);

it('reads an empty array as an empty object when the schema asks for an object', function (): void {
    expect(ValueSchema::accepts(['type' => 'object', 'properties' => []], []))->toBeTrue()
        ->and(ValueSchema::accepts(['type' => 'object', 'required' => ['x']], []))->toBeFalse()
        ->and(ValueSchema::accepts(['type' => 'array'], []))->toBeTrue();
});
