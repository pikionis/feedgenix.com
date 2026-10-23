<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Evals;

/** How one eval feed was mapped against its known answer. */
final readonly class CaseScore
{
    /** @param list<string> $mistakes "field = column (expected …)" */
    public function __construct(
        public string $name,
        public int $expected,
        public int $right,
        public int $wrong,
        public int $wrongGolden,
        public int $protectedInText,
        public ?string $sourceLocale,
        public array $mistakes,
    ) {}

    /** Of what was filled, how much is right. Nothing filled is perfectly precise. */
    public function precision(): float
    {
        return $this->right + $this->wrong === 0 ? 1.0 : $this->right / ($this->right + $this->wrong);
    }

    /** Of what should be filled, how much is. */
    public function recall(): float
    {
        return $this->expected === 0 ? 1.0 : $this->right / $this->expected;
    }

    /**
     * @param  list<self>  $scores
     * @return array{precision: float, recall: float, wrong_golden: int, protected_in_text: int}
     */
    public static function total(array $scores): array
    {
        $right = array_sum(array_map(static fn (self $s): int => $s->right, $scores));
        $wrong = array_sum(array_map(static fn (self $s): int => $s->wrong, $scores));
        $expected = array_sum(array_map(static fn (self $s): int => $s->expected, $scores));

        return [
            'precision' => round($right + $wrong === 0 ? 1.0 : $right / ($right + $wrong), 4),
            'recall' => round($expected === 0 ? 1.0 : $right / $expected, 4),
            'wrong_golden' => array_sum(array_map(static fn (self $s): int => $s->wrongGolden, $scores)),
            'protected_in_text' => array_sum(array_map(static fn (self $s): int => $s->protectedInText, $scores)),
        ];
    }
}
