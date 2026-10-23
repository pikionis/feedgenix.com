<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

/** What a catalogue sync did, for the run and the people reading it. */
final class SyncResult
{
    /** @var array<string, int> */
    public array $counts = [
        'rows' => 0,
        'new' => 0,
        'changed' => 0,
        'unchanged' => 0,
        'no_sku' => 0,
        'duplicates' => 0,
        'stale_translations' => 0,
        'disappeared' => 0,
    ];

    /** @var list<array{code: string, message: string, count: int}> */
    public array $warnings = [];

    public function add(string $count, int $by = 1): void
    {
        $this->counts[$count] = ($this->counts[$count] ?? 0) + $by;
    }

    public function warn(string $code, string $message, int $count): void
    {
        $this->warnings[] = ['code' => $code, 'message' => $message, 'count' => $count];
    }
}
