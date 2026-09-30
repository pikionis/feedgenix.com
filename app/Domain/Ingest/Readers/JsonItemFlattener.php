<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Readers;

use App\Domain\Ingest\Exceptions\UnreadableFeed;
use App\Domain\Ingest\Exceptions\UnreadableReason;

/**
 * Flattens one JSON item into column => exact source text. json_decode() would turn 19.90 into
 * 19.9 and a 20-digit GTIN into a float: golden values would change before anyone saw them. This
 * small parser keeps every number exactly as written.
 *
 *   {"title": "Hammer", "price": 19.90}   title = Hammer, price = 19.90
 *   {"shipping": {"price": 4.95}}         shipping.price = 4.95
 *   {"images": ["a.jpg", "b.jpg"]}        images[0] = a.jpg, images[1] = b.jpg
 *   {"stock": true, "note": null}         stock = true (null and "" are left out)
 */
final class JsonItemFlattener
{
    private int $position = 0;

    private readonly int $length;

    /** @var array<array-key, string> */
    private array $row = [];

    private function __construct(
        private readonly string $json,
        private readonly int $maxDepth,
    ) {
        $this->length = strlen($json);
    }

    /** @return array<array-key, string> */
    public static function flatten(string $json, int $maxDepth = 32): array
    {
        $parser = new self($json, $maxDepth);
        $parser->skipWhitespace();
        $parser->value($parser->peek() === '{' ? '' : 'value', 0);
        $parser->skipWhitespace();
        if ($parser->position !== $parser->length) {
            $parser->fail('unexpected text after the item');
        }

        return $parser->row;
    }

    private function value(string $path, int $depth): void
    {
        if ($depth > $this->maxDepth) {
            throw UnreadableFeed::because(UnreadableReason::TooDeep, "An item nests more than {$this->maxDepth} levels deep.");
        }

        $this->skipWhitespace();
        match ($this->peek()) {
            '{' => $this->object($path, $depth),
            '[' => $this->list($path, $depth),
            '"' => $this->set($path, $this->string()),
            't' => $this->set($path, $this->literal('true')),
            'f' => $this->set($path, $this->literal('false')),
            'n' => $this->literal('null'),
            default => $this->set($path, $this->number()),
        };
    }

    private function object(string $path, int $depth): void
    {
        $this->position++;
        $this->skipWhitespace();
        if ($this->peek() === '}') {
            $this->position++;

            return;
        }

        while (true) {
            $this->skipWhitespace();
            if ($this->peek() !== '"') {
                $this->fail('expected a property name');
            }
            $key = $this->string();
            $this->skipWhitespace();
            $this->expect(':');
            $this->value($path === '' ? $key : $path.'.'.$key, $depth + 1);
            $this->skipWhitespace();

            $next = $this->json[$this->position++] ?? '';
            if ($next === '}') {
                return;
            }
            if ($next !== ',') {
                $this->fail('expected , or }');
            }
        }
    }

    private function list(string $path, int $depth): void
    {
        $this->position++;
        $this->skipWhitespace();
        if ($this->peek() === ']') {
            $this->position++;

            return;
        }

        for ($index = 0; ; $index++) {
            $this->value("{$path}[{$index}]", $depth + 1);
            $this->skipWhitespace();

            $next = $this->json[$this->position++] ?? '';
            if ($next === ']') {
                return;
            }
            if ($next !== ',') {
                $this->fail('expected , or ]');
            }
        }
    }

    private function string(): string
    {
        $start = $this->position;
        $at = $start + 1;
        while (true) {
            $at += strcspn($this->json, '"\\', $at);
            if ($at >= $this->length) {
                $this->fail('unterminated string');
            }
            if ($this->json[$at] === '\\') {
                $at += 2;

                continue;
            }
            break;
        }

        $this->position = $at + 1;
        $decoded = json_decode(substr($this->json, $start, $at - $start + 1));
        if (! is_string($decoded)) {
            $this->fail('invalid string');
        }

        return $decoded;
    }

    private function number(): string
    {
        if (preg_match('/-?(?:0|[1-9]\d*)(?:\.\d+)?(?:[eE][+-]?\d+)?/A', $this->json, $match, 0, $this->position) !== 1) {
            $this->fail('unexpected character');
        }

        $this->position += strlen($match[0]);

        return $match[0];
    }

    private function literal(string $word): string
    {
        if (substr($this->json, $this->position, strlen($word)) !== $word) {
            $this->fail('unexpected character');
        }

        $this->position += strlen($word);

        return $word;
    }

    private function set(string $path, string $value): void
    {
        if ($value !== '') {
            $this->row[$path] = $value;
        }
    }

    private function expect(string $char): void
    {
        if ($this->peek() !== $char) {
            $this->fail("expected {$char}");
        }
        $this->position++;
    }

    private function peek(): string
    {
        return $this->json[$this->position] ?? '';
    }

    private function skipWhitespace(): void
    {
        $this->position += strspn($this->json, " \t\r\n", $this->position);
    }

    private function fail(string $problem): never
    {
        throw UnreadableFeed::because(UnreadableReason::MalformedJson, "The JSON is not valid ({$problem}).");
    }
}
