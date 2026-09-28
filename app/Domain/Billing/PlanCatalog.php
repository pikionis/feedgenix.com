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

    /**
     * The public shape of a plan, for the pricing page and the upgrade dialog.
     *
     * @return array{
     *     key: string,
     *     name: string,
     *     tagline: string,
     *     price_monthly_eur: float,
     *     price_yearly_per_month_eur: float,
     *     price_yearly_eur: float,
     *     yearly_savings_eur: float,
     *     limits: array{feeds: int|null, languages: int|null, products_per_month: int|null, products_per_year: int|null, worker_slots: int|null},
     *     overage_credits_per_product: int|null,
     *     features: array<string, bool>,
     *     models: list<string>
     * }
     */
    public static function publicPlan(string $plan): array
    {
        $definition = self::plan($plan);
        $monthly = (float) (is_int($definition['price_monthly_eur'] ?? null) || is_float($definition['price_monthly_eur'] ?? null) ? $definition['price_monthly_eur'] : 0);
        // Yearly = 12 × the discounted monthly price, paid once for the whole term.
        $yearlyPerMonth = round($monthly * (1 - self::annualDiscount()), 2);
        $perMonth = self::limit($plan, 'products_per_month');

        $features = [];
        foreach ((array) ($definition['features'] ?? []) as $feature => $enabled) {
            $features[(string) $feature] = $enabled === true;
        }

        $models = array_values(array_filter((array) ($definition['models'] ?? []), is_string(...)));
        $tagline = $definition['tagline'] ?? '';

        return [
            'key' => $plan,
            'name' => self::name($plan),
            'tagline' => is_string($tagline) ? $tagline : '',
            'price_monthly_eur' => $monthly,
            'price_yearly_per_month_eur' => $yearlyPerMonth,
            'price_yearly_eur' => round($yearlyPerMonth * 12, 2),
            'yearly_savings_eur' => round(($monthly - $yearlyPerMonth) * 12, 2),
            'limits' => [
                'feeds' => self::limit($plan, 'feeds'),
                'languages' => self::limit($plan, 'languages'),
                'products_per_month' => $perMonth,
                'products_per_year' => $perMonth === null ? null : $perMonth * 12,
                'worker_slots' => self::limit($plan, 'worker_slots'),
            ],
            'overage_credits_per_product' => self::overageCreditsPerProduct($plan),
            'features' => $features,
            'models' => $models,
        ];
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
