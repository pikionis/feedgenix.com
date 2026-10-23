<?php

declare(strict_types=1);

namespace App\Domain\Export\Dialects;

use Generator;

/**
 * Comma-separated values as RFC 4180 writes them: a cell with a comma, a quote or a line break is
 * quoted and its quotes doubled, lines end with CRLF, UTF-8 without a BOM. Every value can be
 * written unchanged, so nothing is ever rewritten (the legacy writer turned every " into ', so
 * 23" became 23').
 */
final class Rfc4180Csv implements Dialect
{
    public function key(): string
    {
        return 'csv';
    }

    public function extension(): string
    {
        return 'csv';
    }

    public function contentType(): string
    {
        return 'text/csv; charset=utf-8';
    }

    public function cell(string $value, bool $exact): string
    {
        return strpbrk($value, ",\"\r\n") === false ? $value : '"'.str_replace('"', '""', $value).'"';
    }

    public function line(array $cells): string
    {
        return implode(',', $cells)."\r\n";
    }

    public function read($stream): Generator
    {
        // An empty escape character: quotes are doubled, a backslash is an ordinary character.
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            yield array_map(static fn (?string $cell): string => (string) $cell, $row);
        }
    }
}
