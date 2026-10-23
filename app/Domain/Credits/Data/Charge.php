<?php

declare(strict_types=1);

namespace App\Domain\Credits\Data;

/**
 * What one billed attempt took from its reservation. $unbilled is the part the reservation could
 * not cover (it was settled meanwhile, or the attempt cost more than the hold left): the platform
 * absorbs it, the customer is never charged past what they reserved.
 */
final readonly class Charge
{
    public function __construct(
        public int $charged,
        public int $unbilled,
    ) {}
}
