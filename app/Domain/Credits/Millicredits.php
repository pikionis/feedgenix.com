<?php

declare(strict_types=1);

namespace App\Domain\Credits;

use Brick\Math\BigDecimal;
use Brick\Math\BigNumber;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Conversions for the ledger's unit. 1 credit = 1,000 millicredits; the euro value of a credit is
 * config (credits.credit_eur). Exact decimal arithmetic only: a float never touches money.
 */
final class Millicredits
{
    public const int PER_CREDIT = 1000;

    /** Euros to millicredits, rounded up to a whole millicredit. */
    public static function fromEuros(BigNumber|string $euros): int
    {
        return BigDecimal::of($euros)
            ->multipliedBy(self::PER_CREDIT)
            ->dividedBy(self::creditEur(), 0, RoundingMode::Up)
            ->toInt();
    }

    /** What a customer reads: whole credits, rounded up (a started credit is a credit). */
    public static function toCredits(int $millicredits): int
    {
        return intdiv($millicredits + self::PER_CREDIT - 1, self::PER_CREDIT);
    }

    private static function creditEur(): BigDecimal
    {
        $value = config('credits.credit_eur');
        $credit = is_string($value) ? BigDecimal::of($value) : throw new InvalidArgumentException('credits.credit_eur must be a decimal string.');

        return $credit->isPositive() ? $credit : throw new InvalidArgumentException('credits.credit_eur must be positive.');
    }
}
