<?php

declare(strict_types=1);

namespace App\Domain\Export\Dialects;

use InvalidArgumentException;

final class Dialects
{
    public static function for(string $key): Dialect
    {
        return match ($key) {
            'csv' => new Rfc4180Csv,
            'tsv' => new Tsv,
            default => throw new InvalidArgumentException("[{$key}] is not csv or tsv."),
        };
    }
}
