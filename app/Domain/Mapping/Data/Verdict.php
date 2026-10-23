<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Data;

/** What a column's values say about a mapping (the legacy FieldValidator verdicts). */
enum Verdict: string
{
    case Pass = 'pass';
    case Warn = 'warn';
    /** The values clearly do not fit: the field is left empty rather than filled wrong. */
    case Fail = 'fail';
    /** No values to judge. */
    case Empty = 'empty';
}
