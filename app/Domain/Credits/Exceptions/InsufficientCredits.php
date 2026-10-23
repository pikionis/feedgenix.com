<?php

declare(strict_types=1);

namespace App\Domain\Credits\Exceptions;

use App\Domain\Credits\Millicredits;
use RuntimeException;

/** The wallet cannot hold this much: the work waits, with the exact numbers (ADR 0005). */
final class InsufficientCredits extends RuntimeException
{
    public function __construct(
        public readonly int $needed,
        public readonly int $available,
    ) {
        parent::__construct(sprintf(
            'This needs %s credits; %s are available.',
            number_format(Millicredits::toCredits($needed)),
            number_format(intdiv(max(0, $available), Millicredits::PER_CREDIT)),
        ));
    }
}
