<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Actions\SetPlan;
use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanCatalog;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Staff tool until Stripe (M6): put a workspace on a plan, with its period and deal overrides.
 * Server access is the authorisation; nothing else writes plan fields except billing webhooks.
 */
#[AsCommand(name: 'tenants:set-plan', description: 'Set a workspace plan, billing interval, paid-through date and limit overrides')]
#[Signature('tenants:set-plan
        {tenant : Workspace id or slug}
        {plan : free, pro, ultra or custom}
        {--interval=month : month or year}
        {--until= : Paid-through date (YYYY-MM-DD); default one interval from today}
        {--override=* : A limit override as key=value ("null" = unlimited, "-" removes it)}')]
final class SetPlanCommand extends Command
{
    public function handle(SetPlan $setPlan): int
    {
        try {
            $tenant = $this->tenant();
            $plan = $this->planArgument();
            $interval = $this->interval();
            $until = $this->until();
            $overrides = $this->overrides($tenant);
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return self::INVALID;
        }

        $applied = $setPlan->handle($tenant, $plan, $interval, $until, $overrides);

        $entitlements = Entitlements::for($tenant);
        $this->info("Workspace {$tenant->slug} (#{$tenant->id}) is on ".PlanCatalog::name($entitlements->plan()).'.');
        $this->line('Interval: '.$entitlements->interval().' · paid until: '.($tenant->plan_ends_at?->toDateString() ?? '—'));
        foreach (['feeds', 'languages', 'products_per_month', 'worker_slots'] as $key) {
            $limit = $entitlements->limit($key);
            $this->line(str_pad($key, 20).($limit === null ? 'unlimited' : number_format($limit)));
        }

        $this->line("Paused {$applied['feeds_paused']} feed(s) and {$applied['languages_paused']} language(s); resumed {$applied['feeds_resumed']} feed(s) and {$applied['languages_resumed']} language(s).");

        return self::SUCCESS;
    }

    private function tenant(): Tenant
    {
        $key = (string) $this->argument('tenant');
        $tenant = ctype_digit($key)
            ? Tenant::query()->find((int) $key)
            : Tenant::query()->where('slug', $key)->first();

        return $tenant ?? throw new InvalidArgumentException("No workspace [{$key}].");
    }

    private function planArgument(): string
    {
        $plan = (string) $this->argument('plan');

        return PlanCatalog::exists($plan) ? $plan : throw new InvalidArgumentException("Unknown plan [{$plan}]: use ".implode(', ', PlanCatalog::LADDER).'.');
    }

    private function interval(): string
    {
        $interval = (string) $this->option('interval');

        return in_array($interval, ['month', 'year'], true) ? $interval : throw new InvalidArgumentException('--interval must be month or year.');
    }

    /** The paid-through date given, or null for one interval from today. */
    private function until(): ?CarbonImmutable
    {
        $until = $this->option('until');
        if ($until === null) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', (string) $until);
        } catch (Throwable) {
            $date = null;
        }

        return $date instanceof CarbonImmutable && $date->format('Y-m-d') === $until
            ? $date->endOfDay()
            : throw new InvalidArgumentException('--until must be a date like 2027-01-31.');
    }

    /** @return array<string, int|null> */
    private function overrides(Tenant $tenant): array
    {
        $overrides = $tenant->limits_override ?? [];

        foreach ((array) $this->option('override') as $option) {
            [$key, $value] = array_pad(explode('=', (string) $option, 2), 2, '');
            PlanCatalog::limit('free', $key); // throws for an unknown limit

            match (true) {
                $value === '-' => $overrides = array_diff_key($overrides, [$key => true]),
                $value === 'null' => $overrides[$key] = null,
                ctype_digit($value) => $overrides[$key] = (int) $value,
                default => throw new InvalidArgumentException("Override [{$option}] must be key=number, key=null or key=-."),
            };
        }

        return $overrides;
    }
}
