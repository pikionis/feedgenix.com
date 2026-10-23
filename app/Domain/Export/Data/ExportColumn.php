<?php

declare(strict_types=1);

namespace App\Domain\Export\Data;

/** One column of an export file: what it is called, which field (in which language) fills it, and how. */
final readonly class ExportColumn
{
    public function __construct(
        public string $header,
        public string $field,
        public ?string $locale,
        public ?string $transform,
        public bool $required,
        // A golden value: written byte for byte or not at all (its row goes to the report).
        public bool $exact,
    ) {}
}
