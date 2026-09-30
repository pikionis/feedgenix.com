<?php

declare(strict_types=1);

use App\Domain\Ingest\Exceptions\UnreadableFeed;
use App\Domain\Ingest\Exceptions\UnreadableReason;
use App\Domain\Ingest\Format;
use App\Domain\Ingest\Readers\JsonItemFlattener;
use App\Domain\Ingest\Readers\JsonRowReader;

it('reads a top-level list of products', function (): void {
    expect(readRows('[{"sku":"A-1","title":"Hammer"},{"sku":"A-2","title":"Nail"}]'))
        ->toBe([['sku' => 'A-1', 'title' => 'Hammer'], ['sku' => 'A-2', 'title' => 'Nail']]);
});

it('finds the products list inside an object, after other properties', function (): void {
    $json = '{"shop":{"name":"X","tags":["a","b"]},"currency":"EUR","count":2,"products":[{"sku":"A-1"},{"sku":"A-2"}]}';

    expect(readRows($json))->toBe([['sku' => 'A-1'], ['sku' => 'A-2']])
        ->and((new JsonRowReader(feedFile($json)))->pointer())->toBe('/products');
});

it('skips a list of plain values when looking for the products', function (): void {
    $json = '{"tags":["a","b"],"items":[{"sku":"A-1"}]}';

    expect((new JsonRowReader(feedFile($json)))->pointer())->toBe('/items');
});

it('keeps every number exactly as written (golden values)', function (): void {
    $row = readRows('[{"price":19.90,"stock":0,"gtin":4006381333931,"big":12345678901234567890,"exp":1e3,"neg":-0.50}]')[0];

    expect($row)->toBe(['price' => '19.90', 'stock' => '0', 'gtin' => '4006381333931', 'big' => '12345678901234567890', 'exp' => '1e3', 'neg' => '-0.50']);
});

it('flattens nested objects and lists like XML, and leaves out null and empty values', function (): void {
    $row = JsonItemFlattener::flatten('{"title":"Mug","shipping":{"price":"4.95","country":"DE"},"images":["a.jpg","b.jpg"],'
        .'"variants":[{"size":"S"},{"size":"M"}],"active":true,"note":null,"empty":"","tags":[],"meta":{}}');

    expect($row)->toBe([
        'title' => 'Mug',
        'shipping.price' => '4.95',
        'shipping.country' => 'DE',
        'images[0]' => 'a.jpg',
        'images[1]' => 'b.jpg',
        'variants[0].size' => 'S',
        'variants[1].size' => 'M',
        'active' => 'true',
    ]);
});

it('decodes escapes and Unicode, including surrogate pairs', function (): void {
    expect(JsonItemFlattener::flatten('{"t":"Tab\\tQuote\\" \\u00e9 \\ud83d\\ude00 \\/"}'))->toBe(['t' => "Tab\tQuote\" é 😀 /"]);
});

it('reads one object per line (NDJSON)', function (): void {
    $read = readFeed(feedFile("{\"sku\":\"A-1\",\"price\":1.10}\n\n{\"sku\":\"A-2\",\"price\":2.20}\n"));

    expect($read['feed']->format)->toBe(Format::NdJson)
        ->and($read['rows'])->toBe([['sku' => 'A-1', 'price' => '1.10'], ['sku' => 'A-2', 'price' => '2.20']]);
});

it('reads a pretty-printed single object with a list as JSON, not as lines', function (): void {
    $json = "{\n  \"products\": [\n    {\"sku\": \"A-1\"}\n  ]\n}\n";

    expect(readFeed(feedFile($json))['feed']->format)->toBe(Format::Json);
});

it('stops on malformed JSON', function (string $json): void {
    try {
        readRows($json);
        $this->fail('expected a refusal');
    } catch (UnreadableFeed $exception) {
        expect($exception->reason)->toBe(UnreadableReason::MalformedJson);
    }
})->with([
    'missing bracket' => '[{"sku":"A-1"},{"sku":"A-2"',
    'bare word' => '[{"sku":A-1}]',
    'trailing comma' => '[{"sku":"A-1",}]',
]);

it('refuses JSON without a list of products', function (): void {
    try {
        readRows('{"shop":"X","count":0}');
        $this->fail('expected a refusal');
    } catch (UnreadableFeed $exception) {
        expect($exception->reason)->toBe(UnreadableReason::NoItems);
    }
});

it('refuses items nested deeper than the cap', function (): void {
    $deep = str_repeat('{"a":', 40).'1'.str_repeat('}', 40);

    expect(fn (): array => JsonItemFlattener::flatten($deep, 32))->toThrow(UnreadableFeed::class, 'levels deep');
});
