<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Readers;

use App\Domain\Ingest\Exceptions\UnreadableFeed;
use App\Domain\Ingest\Exceptions\UnreadableReason;
use App\Domain\Ingest\Warnings;
use DateInterval;
use DateTimeInterface;
use Generator;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Cell\TextRunCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Streams the first visible sheet of an XLSX workbook row by row (OpenSpout), so a workbook with a
 * million rows stays flat in memory. The first non-empty row is the header.
 *
 * Cell text: numbers in their shortest exact form (19.9, 4006381333931), dates as 2026-09-29 or
 * 2026-09-29 14:30:00, booleans as TRUE/FALSE, formulas as their last computed value.
 *
 * Legacy defect D5: XLSX files were loaded into memory whole.
 */
final readonly class XlsxRowReader implements RowReader
{
    private Warnings $warnings;

    /** @param non-empty-string|null $tempDir where OpenSpout may spill shared strings */
    public function __construct(
        private string $path,
        private ?string $tempDir = null,
    ) {
        $this->warnings = new Warnings;
    }

    public function warnings(): Warnings
    {
        return $this->warnings;
    }

    public function rows(): Generator
    {
        $options = $this->tempDir === null ? new Options : new Options(tempFolder: $this->tempDir);
        $reader = new Reader($options);
        try {
            $reader->open($this->path);
        } catch (Throwable) {
            throw UnreadableFeed::because(UnreadableReason::CorruptArchive, 'The Excel file is damaged or password-protected.');
        }

        try {
            $sheets = 0;
            $readSheet = null;
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheets++;
                if ($readSheet !== null || ! $sheet->isVisible()) {
                    continue;
                }

                $readSheet = $sheet->getName();
                yield from $this->sheetRows($sheet->getRowIterator());
            }

            if ($readSheet === null) {
                throw UnreadableFeed::because(UnreadableReason::NoItems, 'The workbook has no visible sheet.');
            }

            if ($sheets > 1) {
                $this->warnings->add('xlsx_more_sheets', "The workbook has {$sheets} sheets; FeedGenix read the first one, “{$readSheet}”.");
            }
        } finally {
            $reader->close();
        }
    }

    /**
     * @param  iterable<Row>  $rows
     * @return Generator<int, array<array-key, string>>
     */
    private function sheetRows(iterable $rows): Generator
    {
        $header = null;
        $width = 0;
        $errors = 0;

        foreach ($rows as $row) {
            $values = [];
            $cells = $row->cells;
            for ($index = 0, $count = $row->getNumCells(); $index < $count; $index++) {
                $cell = $cells[$index] ?? null;
                if ($cell instanceof ErrorCell) {
                    $errors++;
                }
                $values[] = $cell === null ? '' : $this->text($cell);
            }

            if (implode('', $values) === '' || trim(implode('', $values)) === '') {
                continue;
            }

            if ($header === null) {
                $header = ColumnNames::normalize($values);
                $width = count($header);

                continue;
            }

            $values = array_slice(array_pad($values, $width, ''), 0, $width);

            yield array_combine($header, $values);
        }

        if ($header === null) {
            throw UnreadableFeed::because(UnreadableReason::NoHeader, 'The first sheet is empty.');
        }

        if ($errors > 0) {
            $this->warnings->add('excel_errors', "{$errors} cells hold an Excel error (#N/A, #REF!…) and were read as empty.", $errors);
        }
    }

    private function text(Cell $cell): string
    {
        $value = match (true) {
            $cell instanceof FormulaCell => $cell->getComputedValue(),
            $cell instanceof TextRunCell => $cell->getStringValue(),
            $cell instanceof ErrorCell => null,
            default => $cell->getValue(),
        };

        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'TRUE' : 'FALSE',
            is_int($value) => (string) $value,
            is_float($value) => self::number($value),
            $value instanceof DateTimeInterface => $this->date($value),
            $value instanceof DateInterval => $this->duration($value),
            default => '',
        };
    }

    /** The shortest text that reads back as the same number: 19.9, not 19.899999999999999. */
    public static function number(float $number): string
    {
        if (! is_finite($number)) {
            return '';
        }

        if (floor($number) === $number && abs($number) < 1e15) {
            return number_format($number, 0, '.', '');
        }

        // json_encode writes the shortest text that reads back as the same float (serialize_precision -1).
        return json_encode($number, JSON_THROW_ON_ERROR);
    }

    private function date(DateTimeInterface $value): string
    {
        return $value->format('H:i:s') === '00:00:00' ? $value->format('Y-m-d') : $value->format('Y-m-d H:i:s');
    }

    private function duration(DateInterval $value): string
    {
        $hours = (int) $value->days * 24 + $value->h;

        return sprintf('%02d:%02d:%02d', $hours, $value->i, $value->s);
    }
}
