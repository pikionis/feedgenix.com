<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Readers;

use App\Domain\Ingest\Exceptions\UnreadableFeed;
use App\Domain\Ingest\Exceptions\UnreadableReason;
use App\Domain\Ingest\IngestLimits;
use Generator;

/** The row, column and cell caps, applied the same way to every format. */
final class LimitedRows
{
    /** @return Generator<int, array<array-key, string>> */
    public static function of(RowReader $reader, IngestLimits $limits): Generator
    {
        $rows = 0;
        $columns = [];

        foreach ($reader->rows() as $row) {
            $rows++;
            if ($rows > $limits->maxRows) {
                throw UnreadableFeed::because(UnreadableReason::TooManyRows, sprintf('The feed has more than %s rows, the most one import can hold.', number_format($limits->maxRows)));
            }

            foreach ($row as $name => $value) {
                if (strlen($value) > $limits->maxCellBytes) {
                    throw UnreadableFeed::because(UnreadableReason::CellTooLarge, sprintf('Row %d, column “%s” holds %s KB; one value may hold at most %s KB.', $rows, $name, number_format(strlen($value) / 1024), number_format($limits->maxCellBytes / 1024)));
                }

                if (! isset($columns[$name])) {
                    $columns[$name] = true;
                    if (count($columns) > $limits->maxColumns) {
                        throw UnreadableFeed::because(UnreadableReason::TooManyColumns, sprintf('The feed has more than %s columns.', number_format($limits->maxColumns)));
                    }
                }
            }

            yield $row;
        }
    }
}
