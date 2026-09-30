<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Readers;

use App\Domain\Ingest\Exceptions\UnreadableFeed;
use App\Domain\Ingest\Exceptions\UnreadableReason;
use App\Domain\Ingest\Warnings;
use Generator;
use JsonMachine\Exception\JsonMachineException;
use JsonMachine\Items;
use JsonMachine\JsonDecoder\PassThruDecoder;

/**
 * Streams JSON feeds item by item (json-machine), whatever their size:
 * - a top-level list: [ {...}, {...} ];
 * - an object whose first list of objects holds the items: {"products": [ {...} ]};
 * - one object per line (NDJSON / JSON Lines).
 */
final readonly class JsonRowReader implements RowReader
{
    private const POINTER_WINDOW = 1 << 20;

    private Warnings $warnings;

    public function __construct(
        private string $path,
        private bool $perLine = false,
        private int $maxDepth = 32,
    ) {
        $this->warnings = new Warnings;
    }

    public function warnings(): Warnings
    {
        return $this->warnings;
    }

    public function rows(): Generator
    {
        $found = 0;
        $items = $this->perLine ? $this->lines() : $this->items($this->pointer());
        foreach ($items as $raw) {
            $found++;
            yield JsonItemFlattener::flatten($raw, $this->maxDepth);
        }

        if ($found === 0) {
            throw UnreadableFeed::because(UnreadableReason::NoItems, 'The JSON has no items.');
        }
    }

    /**
     * The JSON Pointer to the list of items: '' for a top-level list, "/products" for the first
     * property that is a list of objects.
     */
    public function pointer(): string
    {
        $handle = fopen($this->path, 'rb');
        $head = $handle === false ? '' : (string) fread($handle, self::POINTER_WINDOW);
        if ($handle !== false) {
            fclose($handle);
        }

        $scan = new JsonScanner($head);
        $scan->skipWhitespace();
        if ($scan->peek() === '[') {
            return '';
        }

        $key = $scan->peek() === '{' ? $scan->firstListOfObjects() : null;
        if ($key === null) {
            throw UnreadableFeed::because(UnreadableReason::NoItems, 'No list of products was found in the JSON (expected a list, or an object with one).');
        }

        return '/'.str_replace(['~', '/'], ['~0', '~1'], $key);
    }

    /** @return Generator<int, string> */
    private function items(string $pointer): Generator
    {
        try {
            foreach (Items::fromFile($this->path, ['pointer' => $pointer, 'decoder' => new PassThruDecoder]) as $raw) {
                yield (string) $raw;
            }
        } catch (JsonMachineException $exception) {
            throw UnreadableFeed::because(UnreadableReason::MalformedJson, 'The JSON is not valid: '.$exception->getMessage());
        }
    }

    /** @return Generator<int, string> */
    private function lines(): Generator
    {
        $handle = fopen($this->path, 'rb');
        if ($handle === false) {
            return;
        }

        try {
            while (($line = fgets($handle)) !== false) {
                if (trim($line) !== '') {
                    yield $line;
                }
            }
        } finally {
            fclose($handle);
        }
    }
}
