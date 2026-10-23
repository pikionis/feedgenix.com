<?php

declare(strict_types=1);

namespace App\Domain\Credits\Exceptions;

use App\Domain\Credits\Millicredits;
use Illuminate\Http\JsonResponse;
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

    /** 409, not 402: a 402 means "upgrade the plan" to the app; this is "top up or wait". */
    public function render(): JsonResponse
    {
        return response()->json([
            'error' => 'insufficient_credits',
            'message' => $this->getMessage(),
            'needed_credits' => Millicredits::toCredits($this->needed),
            'available_credits' => intdiv(max(0, $this->available), Millicredits::PER_CREDIT),
        ], 409);
    }
}
