<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Entitlements;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Translation\Models\WorkUnit;
use App\Domain\Translation\RunProgress;
use App\Domain\Translation\WorkDispatcher;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The one reconciler (ADR 0006), every minute on one server. It never fetches a feed and never
 * calls AI. Each pass: expired leases go back to pending; units held for credits or AI are tried
 * again after five minutes (the executor holds them again if the cause remains); units held for
 * the allowance resume in a new period; runs whose units are all finished advance; then the
 * dispatcher pumps every workspace.
 */
#[AsCommand(name: 'ops:reconcile', description: 'Requeue expired leases, release cleared holds, advance finished runs, pump the AI work')]
final class ReconcileCommand extends Command
{
    private const array RETRY_HOLDS = ['credits', 'ai_disabled', 'unpriced_model', 'provider_unavailable', 'ai_paused', 'reservation_exhausted', 'owner_unverified'];

    public function handle(TenantContext $context, WorkDispatcher $dispatcher): int
    {
        $touched = $context->runAsSystem('reconcile AI work', function (): array {
            $runs = [];
            $expired = WorkUnit::query()->where('status', 'leased')->where('lease_until', '<', now());
            $runs = [...$runs, ...(clone $expired)->pluck('run_id')->all()];
            $requeued = $expired->update(['status' => 'pending', 'lease_token' => null, 'lease_until' => null]);

            $retry = WorkUnit::query()->where('status', 'held')->whereIn('hold_reason', self::RETRY_HOLDS)->where('updated_at', '<', now()->subMinutes(5));
            $runs = [...$runs, ...(clone $retry)->pluck('run_id')->all()];
            $released = $retry->update(['status' => 'pending', 'hold_reason' => null]);

            $allowance = 0;
            foreach (WorkUnit::query()->where('status', 'held')->where('hold_reason', 'allowance')->get(['id', 'tenant_id', 'run_id', 'updated_at']) as $unit) {
                if ($unit->updated_at !== null && $unit->updated_at->lessThan(Entitlements::for($unit->tenant_id)->period()['start'])) {
                    WorkUnit::query()->whereKey($unit->id)->update(['status' => 'pending', 'hold_reason' => null]);
                    $runs[] = $unit->run_id;
                    $allowance++;
                }
            }
            $this->line("Requeued {$requeued} expired lease(s), released {$released} hold(s) and {$allowance} for a new period.");

            return array_values(array_unique(array_map(intval(...), $runs)));
        });

        foreach ($touched as $runId) {
            $tenant = $context->runAsSystem('find the workspace of a run', static fn (): ?int => WorkUnit::query()->where('run_id', $runId)->value('tenant_id'));
            if ($tenant !== null) {
                $context->run((int) $tenant, static fn () => RunProgress::refresh($runId));
            }
        }

        $this->line('Dispatched '.$dispatcher->pump().' unit(s).');

        return self::SUCCESS;
    }
}
