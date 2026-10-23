<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Data;

/** The verdict on a column's values for a field, with the issues behind it. */
final readonly class Validation
{
    /** @param list<array{type: string, severity: 'error'|'warning'|'info', count: int, message: string}> $issues */
    public function __construct(
        public Verdict $verdict,
        public array $issues,
        public int $sampled,
    ) {}

    /** @param list<array{type: string, severity: 'error'|'warning'|'info', count: int, message: string}> $issues */
    public static function of(array $issues, int $sampled): self
    {
        $severities = array_column($issues, 'severity');
        $verdict = match (true) {
            $sampled === 0 => Verdict::Empty,
            in_array('error', $severities, true) => Verdict::Fail,
            in_array('warning', $severities, true) => Verdict::Warn,
            default => Verdict::Pass,
        };

        return new self($verdict, $issues, $sampled);
    }

    public function firstMessage(): ?string
    {
        foreach ($this->issues as $issue) {
            if ($issue['severity'] !== 'info') {
                return $issue['message'];
            }
        }

        return null;
    }
}
