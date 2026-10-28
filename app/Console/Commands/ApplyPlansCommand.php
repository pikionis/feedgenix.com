<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanLimitsApplier;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Every hour: a plan can lapse by date alone (paid through yesterday, the grace days over), with
 * no event to act on, so every workspace whose plan in force differs from the one its pauses were
 * set for is brought in line (ADR 0024).
 */
#[AsCommand(name: 'plans:apply', description: 'Pause or resume feeds and languages of workspaces whose plan in force changed')]
final class ApplyPlansCommand extends Command
{
    public function handle(PlanLimitsApplier $limits): int
    {
        $changed = 0;
        Tenant::query()->orderBy('id')->chunkById(200, function ($tenants) use ($limits, &$changed): void {
            foreach ($tenants as $tenant) {
                /** @var Tenant $tenant */
                if ($tenant->applied_plan !== Entitlements::for($tenant)->plan()) {
                    $limits->apply($tenant);
                    $changed++;
                }
            }
        });
        $this->line("Applied the plan of {$changed} workspace(s).");

        return self::SUCCESS;
    }
}
