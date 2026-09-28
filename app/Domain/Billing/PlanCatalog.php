<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use InvalidArgumentException;

/**
 * Typed access to config/plans.php. Public views never include the `internal` guards, costs or
 * the credit markup.
 */
final class PlanCatalog
{
    /** Cheapest to most generous: upgrade suggestions walk this. */
    public const array LADDER = ['free', 'pro', 'ultra', 'custom'];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::plans());
    }

    public static function exists(string $plan): bool
    {
        return array_key_exists($plan, self::plans());
    }

    public static function name(string $plan): string
    {
        $name = self::plan($plan)['name'] ?? null;

        return is_string($name) ? $name : ucfirst($plan);
    }

    /**
     * A limit by key: plan limits first, then internal guards. null = unlimited.
     *
     * @throws InvalidArgumentException for a key no plan defines
     */
    public static function limit(string $plan, string $key): ?int
    {
        $definition = self::plan($plan);

        foreach (['limits', 'internal'] as $group) {
            $values = $definition[$group] ?? [];
            if (is_array($values) && array_key_exists($key, $values)) {
                $value = $values[$key];

                return is_int($value) ? $value : null;
            }
        }

        throw new InvalidArgumentException("Unknown limit [{$key}].");
    }

    public static function feature(string $plan, string $feature): bool
    {
        $features = self::plan($plan)['features'] ?? [];

        return is_array($features) && ($features[$feature] ?? false) === true;
    }

    public static function overageCreditsPerProduct(string $plan): ?int
    {
        $overage = self::plan($plan)['overage'] ?? null;
        $credits = is_array($overage) ? ($overage['credits_per_product'] ?? null) : null;

        return is_int($credits) ? $credits : null;
    }

    public static function annualDiscount(): float
    {
        $discount = config('plans.annual_discount');

        return is_float($discount) || is_int($discount) ? (float) $discount : 0.0;
    }

    /** The monthly list price in euros (0 for Free). */
    public static function monthlyPrice(string $plan): float
    {
        $price = self::plan($plan)['price_monthly_eur'] ?? 0;

        return is_int($price) || is_float($price) ? (float) $price : 0.0;
    }

    public static function tagline(string $plan): string
    {
        $tagline = self::plan($plan)['tagline'] ?? '';

        return is_string($tagline) ? $tagline : '';
    }

    /** @return array<string, array<string, mixed>> */
    private static function plans(): array
    {
        $plans = config('plans.plans');
        if (! is_array($plans)) {
            throw new InvalidArgumentException('config/plans.php has no plans.');
        }

        /** @var array<string, array<string, mixed>> $plans */
        return $plans;
    }

    /** @return array<string, mixed> */
    private static function plan(string $plan): array
    {
        return self::plans()[$plan] ?? throw new InvalidArgumentException("Unknown plan [{$plan}].");
    }
}
