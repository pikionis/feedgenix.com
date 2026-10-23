<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Billing\Entitlements;
use App\Domain\Catalog\Actions\StartCatalogSync;
use App\Domain\Export\Actions\QueueExportBuild;
use App\Domain\Export\Jobs\BuildExportJob;
use App\Domain\Export\Models\ExportArtifact;
use App\Domain\Export\Models\ExportProfile;
use App\Domain\Feeds\Models\PipelineRun;
use App\Domain\Ingest\ImportStatus;
use App\Domain\Ingest\Models\Import;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Translation\Models\WorkUnit;
use App\Domain\Translation\RunProgress;
use App\Domain\Translation\WorkDispatcher;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * The one reconciler (ADR 0006), every minute on one server. It never fetches a feed and never
 * calls AI. Each pass: expired leases go back to pending; units held for credits or AI are tried
 * again after five minutes (the executor holds them again if the cause remains); units held for
 * the allowance resume in a new period; runs whose units are all finished advance; a file that
 * arrived while its feed was busy is read now that it is free (ADR 0019); exports whose catalogue
 * changed since their last build are built while nothing reads or translates the feed, and builds
 * killed mid-way are marked failed (ADR 0022); then the dispatcher pumps every workspace.
 */
#[AsCommand(name: 'ops:reconcile', description: 'Requeue expired leases, release cleared holds, advance finished runs, pump the AI work')]
final class ReconcileCommand extends Command
{
    private const array RETRY_HOLDS = ['credits', 'ai_disabled', 'unpriced_model', 'provider_unavailable', 'ai_paused', 'reservation_exhausted', 'owner_unverified'];

    public function handle(TenantContext $context, WorkDispatcher $dispatcher, StartCatalogSync $sync, QueueExportBuild $exports): int
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

        // The feed's current file is newer than any run read, and nothing runs: read it now.
        $waiting = $context->runAsSystem('find files that arrived while their feed was busy', static fn (): array => DB::select(<<<'SQL'
            SELECT f.tenant_id, latest.id AS import_id
            FROM feeds f
            CROSS JOIN LATERAL (SELECT max(i.id) AS id FROM imports i WHERE i.feed_id = f.id AND i.status = ?) latest
            WHERE latest.id > (SELECT coalesce(max(r.import_id), 0) FROM pipeline_runs r WHERE r.feed_id = f.id)
              AND EXISTS (SELECT 1 FROM pipeline_runs r WHERE r.feed_id = f.id)
              AND NOT EXISTS (SELECT 1 FROM pipeline_runs r WHERE r.feed_id = f.id AND r.status IN (?, ?, ?, ?))
            SQL, [ImportStatus::Profiled->value, ...PipelineRun::UNFINISHED]));
        $started = 0;
        foreach ($waiting as $row) {
            $started += (int) $context->run((int) $row->tenant_id, static fn (): bool => ($import = Import::query()->find($row->import_id)) instanceof Import && $sync->forImport($import) instanceof PipelineRun);
        }
        $this->line("Started {$started} deferred catalogue read(s).");

        $context->runAsSystem('fail export builds killed mid-way', static fn (): int => ExportArtifact::query()->where('status', 'building')
            ->where('created_at', '<', now()->subSeconds(BuildExportJob::TIMEOUT + 600))
            ->update(['status' => 'failed', 'failure_reason' => 'interrupted', 'failure_message' => 'The build stopped unexpectedly. It runs again when the catalogue next changes, or when you build it now.']));
        $due = $context->runAsSystem('find exports to build', static fn (): array => DB::select(<<<'SQL'
            SELECT p.id, p.tenant_id
            FROM export_profiles p JOIN feeds f ON f.id = p.feed_id
            WHERE p.status = 'active'
              AND NOT EXISTS (SELECT 1 FROM export_artifacts a WHERE a.profile_id = p.id AND a.created_at >= coalesce(f.content_changed_at, '-infinity'))
              AND NOT EXISTS (SELECT 1 FROM pipeline_runs r WHERE r.feed_id = f.id AND r.stage IN ('sync', 'translate') AND r.status IN ('queued', 'running'))
            SQL));
        foreach ($due as $row) {
            $context->run((int) $row->tenant_id, static function () use ($row, $exports): void {
                $profile = ExportProfile::query()->find((int) $row->id);
                if ($profile instanceof ExportProfile) {
                    $exports->handle($profile);
                }
            });
        }
        $this->line('Queued '.count($due).' export build(s).');

        $this->line('Dispatched '.$dispatcher->pump().' unit(s).');

        return self::SUCCESS;
    }
}
