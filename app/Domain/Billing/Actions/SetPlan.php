<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\PlanCatalog;
use App\Domain\Billing\PlanLimitsApplier;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Put a workspace on a plan, as staff do until Stripe (ADR 0008): the plan, its interval, the
 * paid-through date and the deal overrides, then the plan's limits applied at once (what is over
 * them pauses, what fits again resumes, ADR 0024). The one way a plan is set by hand: the
 * `tenants:set-plan` command and the staff area both come here.
 */
final readonly class SetPlan
{
    public function __construct(private PlanLimitsApplier $limits) {}

    /**
     * @param  string  $interval  month or year (ignored for Free)
     * @param  CarbonImmutable|null  $until  paid through; default one interval from now
     * @param  array<string, int|null>|null  $overrides  null keeps the workspace's own
     * @return array{plan: string, feeds_paused: int, feeds_resumed: int, languages_paused: int, languages_resumed: int}
     *
     * @throws InvalidArgumentException
     */
    public function handle(Tenant $tenant, string $plan, string $interval = 'month', ?CarbonImmutable $until = null, ?array $overrides = null): array
    {
        if (! PlanCatalog::exists($plan)) {
            throw new InvalidArgumentException("Unknown plan [{$plan}]: use ".implode(', ', PlanCatalog::LADDER).'.');
        }
        if (! in_array($interval, ['month', 'year'], true)) {
            throw new InvalidArgumentException('The interval must be month or year.');
        }
        $overrides ??= $tenant->limits_override ?? [];

        $changes = ['limits_override' => $overrides === [] ? null : $overrides, 'plan_status' => 'active'];
        if ($plan === 'free') {
            $changes += ['plan' => 'free', 'billing_interval' => null, 'plan_started_at' => null, 'plan_ends_at' => null];
        } else {
            $isNewTerm = $tenant->plan !== $plan || $tenant->billing_interval !== $interval || $tenant->plan_started_at === null;
            $changes += [
                'plan' => $plan,
                'billing_interval' => $interval,
                'plan_started_at' => $isNewTerm ? CarbonImmutable::now() : $tenant->plan_started_at,
                'plan_ends_at' => $until ?? ($interval === 'year' ? CarbonImmutable::now()->addYear() : CarbonImmutable::now()->addMonth()),
            ];
        }

        $tenant->forceFill($changes)->save();

        return $this->limits->apply($tenant);
    }
}
