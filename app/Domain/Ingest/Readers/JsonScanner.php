<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Readers;

/**
 * Walks the start of a JSON document (a bounded window, never the whole file) to find where the
 * items are. It only skips over values; nothing is decoded but property names.
 */
final class JsonScanner
{
    private int $position = 0;

    private readonly int $length;

    public function __construct(private readonly string $json)
    {
        $this->length = strlen($json);
    }

    public function peek(): string
    {
        return $this->json[$this->position] ?? '';
    }

    public function skipWhitespace(): void
    {
        $this->position += strspn($this->json, " \t\r\n", $this->position);
    }

    /** The name of the top-level object's first property that is a list of objects, or null. */
    public function firstListOfObjects(): ?string
    {
        $this->position++; // {
        while ($this->position < $this->length) {
            $this->skipWhitespace();
            if ($this->peek() !== '"') {
                return null;
            }

            $key = $this->string();
            $this->skipWhitespace();
            if ($this->peek() !== ':') {
                return null;
            }
            $this->position++;
            $this->skipWhitespace();

            if ($this->peek() === '[') {
                $after = $this->position + 1;
                $after += strspn($this->json, " \t\r\n", $after);
                if (($this->json[$after] ?? '') === '{') {
                    return $key;
                }
            }

            if (! $this->skipValue()) {
                return null;
            }
            $this->skipWhitespace();
            if ($this->peek() !== ',') {
                return null;
            }
            $this->position++;
        }

        return null;
    }

    private function string(): ?string
    {
        $start = $this->position;
        $at = $start + 1;
        while (true) {
            $at += strcspn($this->json, '"\\', $at);
            if ($at >= $this->length) {
                $this->position = $this->length;

                return null;
            }
            if ($this->json[$at] === '\\') {
                $at += 2;

                continue;
            }
            break;
        }

        $this->position = $at + 1;
        $decoded = json_decode(substr($this->json, $start, $at - $start + 1));

        return is_string($decoded) ? $decoded : null;
    }

    /** Skips one value; false when the window ends inside it. */
    private function skipValue(): bool
    {
        $char = $this->peek();
        if ($char === '"') {
            return $this->string() !== null;
        }

        if ($char !== '{' && $char !== '[') {
            $this->position += strcspn($this->json, ",}] \t\r\n", $this->position);

            return $this->position < $this->length;
        }

        $depth = 0;
        while ($this->position < $this->length) {
            $this->position += strcspn($this->json, '"{}[]', $this->position);
            $current = $this->peek();
            if ($current === '"') {
                if ($this->string() === null) {
                    return false;
                }

                continue;
            }

            $this->position++;
            $depth += in_array($current, ['{', '['], true) ? 1 : -1;
            if ($depth === 0) {
                return true;
            }
        }

        return false;
    }
}
