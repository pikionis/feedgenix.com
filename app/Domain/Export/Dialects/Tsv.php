<?php

declare(strict_types=1);

namespace App\Domain\Export\Dialects;

use Generator;
use RuntimeException;

/**
 * Tab-separated values as shopping channels read them: no quoting at all, one line per row, LF.
 * Tabs and line breaks inside a text become one space (literal ones split rows in the legacy
 * feeds). A golden value holding one cannot be written unchanged: its row goes to the report.
 */
final class Tsv implements Dialect
{
    public function key(): string
    {
        return 'tsv';
    }

    public function extension(): string
    {
        return 'tsv';
    }

    public function contentType(): string
    {
        return 'text/tab-separated-values; charset=utf-8';
    }

    public function cell(string $value, bool $exact): ?string
    {
        if (strpbrk($value, "\t\r\n") === false) {
            return $value;
        }

        return $exact ? null : trim((string) preg_replace('/[\t\r\n]+/', ' ', $value));
    }

    public function line(array $cells): string
    {
        return implode("\t", $cells)."\n";
    }

    public function read($stream): Generator
    {
        while (($line = fgets($stream)) !== false) {
            if (! str_ends_with($line, "\n")) {
                throw new RuntimeException('The last line has no line end: the file is cut off.');
            }
            yield explode("\t", substr($line, 0, -1));
        }
    }
}
