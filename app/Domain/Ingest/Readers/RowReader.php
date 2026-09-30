<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Readers;

use App\Domain\Ingest\Warnings;
use Generator;

/**
 * Streams a feed's rows as column name => exact source text. Memory stays flat whatever the
 * file's size.
 *
 * Keys are column names; PHP turns a numeric name such as "2024" into an int key, so callers cast
 * keys with (string). Values are never trimmed, reformatted or parsed as numbers, except that XML
 * text is trimmed of the indentation around it (the golden rule starts here).
 */
interface RowReader
{
    /** @return Generator<int, array<array-key, string>> */
    public function rows(): Generator;

    /** What was noticed while reading; complete once rows() has been consumed. */
    public function warnings(): Warnings;
}
