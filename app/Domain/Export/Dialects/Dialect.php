<?php

declare(strict_types=1);

namespace App\Domain\Export\Dialects;

use Generator;

/**
 * How cells become a file, and back (ADR 0007). The build checks every file by reading it back
 * through the same dialect that wrote it, so the writer and the check can never disagree (the
 * legacy check parsed with other escaping rules than its writer used).
 */
interface Dialect
{
    public function key(): string;

    public function extension(): string;

    public function contentType(): string;

    /**
     * A value as it is written, or null when it cannot be written unchanged. `$exact` values
     * (golden fields, headers) are never altered; others may be made safe (control characters).
     */
    public function cell(string $value, bool $exact): ?string;

    /** @param list<string> $cells already passed through cell() */
    public function line(array $cells): string;

    /**
     * The rows of a file this dialect wrote, header first.
     *
     * @param  resource  $stream
     * @return Generator<int, list<string>>
     */
    public function read($stream): Generator;
}
