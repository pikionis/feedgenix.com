<?php

declare(strict_types=1);

namespace App\Domain\Ingest;

/**
 * Things worth telling the customer that did not stop the import, grouped by code with a count
 * ("12 rows had fewer fields than the header").
 */
final class Warnings
{
    /** @var array<string, array{code: string, message: string, count: int}> */
    private array $items = [];

    public function add(string $code, string $message, int $count = 1): void
    {
        if (isset($this->items[$code])) {
            $this->items[$code]['count'] += $count;

            return;
        }

        $this->items[$code] = ['code' => $code, 'message' => $message, 'count' => $count];
    }

    public function merge(self $other): void
    {
        foreach ($other->all() as $warning) {
            $this->add($warning['code'], $warning['message'], $warning['count']);
        }
    }

    public function has(string $code): bool
    {
        return isset($this->items[$code]);
    }

    public function count(string $code): int
    {
        return $this->items[$code]['count'] ?? 0;
    }

    /** @return list<array{code: string, message: string, count: int}> */
    public function all(): array
    {
        return array_values($this->items);
    }
}
