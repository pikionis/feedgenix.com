<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Tenancy\Models\TenantLanguage;
use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * What one workspace may do right now: its plan in force, allowance period, limits and features,
 * derived from config/plans.php and the tenant's plan columns. Every limit check goes through here.
 */
final readonly class TenantEntitlements
{
    public function __construct(public Tenant $tenant) {}

    /** The platform's own internal workspaces are exempt from every limit. */
    public function isExempt(): bool
    {
        return $this->tenant->is_internal;
    }

    /**
     * The plan in force now. A paid plan falls back to Free once expired, or past its paid-through
     * date plus the grace days. Computed on read, so it is right even when no job has run.
     */
    public function plan(): string
    {
        if ($this->isExempt()) {
            return 'custom';
        }

        $plan = $this->tenant->plan;
        if ($plan === 'free' || ! PlanCatalog::exists($plan) || $this->tenant->plan_status === 'expired') {
            return 'free';
        }

        $endsAt = $this->tenant->plan_ends_at;
        $graceDays = config('plans.grace_days');
        if ($endsAt !== null && CarbonImmutable::now()->greaterThan($endsAt->addDays(is_int($graceDays) ? $graceDays : 3))) {
            return 'free';
        }

        return $plan;
    }

    public function isPaid(): bool
    {
        return $this->plan() !== 'free';
    }

    /** 'year' only for a yearly plan in force; Free and monthly plans run on monthly slices. */
    public function interval(): string
    {
        return $this->isPaid() && $this->tenant->billing_interval === 'year' ? 'year' : 'month';
    }

    /**
     * The allowance window: the plan year for yearly plans (one pool), otherwise the month slice.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function period(): array
    {
        if ($this->interval() === 'year') {
            return $this->slice($this->tenant->plan_started_at ?? $this->anchor(), CarbonImmutable::now(), 'year');
        }

        return $this->monthSlice();
    }

    /**
     * The current month slice, anchored at the allowance clock. Products are counted at most once
     * per slice and monthly caps reset on it.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    public function monthSlice(): array
    {
        return $this->slice($this->anchor(), CarbonImmutable::now(), 'month');
    }

    /** A limit by key (null = unlimited): deal overrides win over the plan, which wins over guards. */
    public function limit(string $key): ?int
    {
        if ($this->isExempt()) {
            return null;
        }

        $override = $this->tenant->limits_override ?? [];
        if (array_key_exists($key, $override)) {
            return $override[$key];
        }

        return PlanCatalog::limit($this->plan(), $key);
    }

    /** Products allowed in the current period: yearly is one pool of 12 × the monthly allowance. */
    public function productAllowance(): ?int
    {
        $monthly = $this->limit('products_per_month');

        return match (true) {
            $monthly === null => null,
            $this->interval() === 'year' => $monthly * 12,
            default => $monthly,
        };
    }

    public function can(string $feature): bool
    {
        return $this->isExempt() || PlanCatalog::feature($this->plan(), $feature);
    }

    /** Current usage of a countable limit. */
    public function used(string $key): int
    {
        return match ($key) {
            'languages' => resolve(TenantContext::class)->run(
                $this->tenant->id,
                static fn (): int => TenantLanguage::query()->count(),
            ),
            default => throw new InvalidArgumentException("No usage counter for [{$key}]."),
        };
    }

    public function remaining(string $key): ?int
    {
        $limit = $this->limit($key);

        return $limit === null ? null : max(0, $limit - $this->used($key));
    }

    /**
     * @param  int|null  $used  the current usage when the caller already knows it
     *
     * @throws PlanLimitExceeded
     */
    public function assertWithin(string $key, int $adding = 1, ?int $used = null): void
    {
        $limit = $this->limit($key);
        if ($limit === null) {
            return;
        }

        $used ??= $this->used($key);
        if ($used + $adding > $limit) {
            throw PlanLimitExceeded::limit($this->plan(), $key, $limit, $used, $used + $adding, $this->upgradeFor($key, $used + $adding));
        }
    }

    /** @throws PlanLimitExceeded */
    public function assertCan(string $feature): void
    {
        if (! $this->can($feature)) {
            throw PlanLimitExceeded::feature($this->plan(), $feature, $this->upgradeForFeature($feature));
        }
    }

    /** The cheapest higher plan whose limit fits $needed, or null ("talk to us"). */
    public function upgradeFor(string $key, int $needed): ?string
    {
        foreach ($this->higherPlans() as $plan) {
            $limit = PlanCatalog::limit($plan, $key);
            if ($limit === null || $limit >= $needed) {
                return $plan;
            }
        }

        return null;
    }

    public function upgradeForFeature(string $feature): ?string
    {
        foreach ($this->higherPlans() as $plan) {
            if (PlanCatalog::feature($plan, $feature)) {
                return $plan;
            }
        }

        return null;
    }

    /** @return list<string> */
    private function higherPlans(): array
    {
        $position = array_search($this->plan(), PlanCatalog::LADDER, true);

        return array_slice(PlanCatalog::LADDER, $position === false ? 0 : $position + 1);
    }

    private function anchor(): CarbonImmutable
    {
        return $this->tenant->usage_anchor_at;
    }

    /**
     * The [start, end) window of length $unit that contains $now, counted from $anchor. Month-end
     * anchors do not drift: 31 Jan → 28 Feb → 31 Mar.
     *
     * @return array{start: CarbonImmutable, end: CarbonImmutable}
     */
    private function slice(CarbonImmutable $anchor, CarbonImmutable $now, string $unit): array
    {
        $at = static fn (int $k): CarbonImmutable => $unit === 'year' ? $anchor->addYearsNoOverflow($k) : $anchor->addMonthsNoOverflow($k);

        if ($now->lessThan($anchor)) {
            return ['start' => $anchor, 'end' => $at(1)];
        }

        $n = max(0, (int) floor($unit === 'year' ? $anchor->diffInYears($now) : $anchor->diffInMonths($now)));
        while ($at($n + 1)->lessThanOrEqualTo($now)) {
            $n++;
        }
        while ($n > 0 && $at($n)->greaterThan($now)) {
            $n--;
        }

        return ['start' => $at($n), 'end' => $at($n + 1)];
    }
}
