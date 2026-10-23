<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Jobs\SyncCatalogJob;
use App\Domain\Feeds\Models\PipelineRun;
use App\Domain\Ingest\ImportStatus;
use App\Domain\Ingest\Models\Import;
use App\Domain\Mapping\Models\Mapping;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Starts reading an import into the catalogue through the feed's approved mapping for its columns.
 * One unfinished run per feed: a run that waits for a person's decision is superseded by the new
 * file; a run still working is left to finish (the reconciler starts the next one).
 */
final class StartCatalogSync
{
    public function forImport(Import $import): ?PipelineRun
    {
        if ($import->status !== ImportStatus::Profiled || $import->column_signature === null) {
            return null;
        }
        $mapping = Mapping::query()->where('feed_id', $import->feed_id)->where('column_signature', $import->column_signature)->first();

        return $mapping instanceof Mapping && $mapping->isApproved() ? $this->start($mapping, $import) : null;
    }

    /** The latest profiled import with the mapping's columns, read through it. */
    public function forMapping(Mapping $mapping): ?PipelineRun
    {
        $import = Import::query()->where('feed_id', $mapping->feed_id)->where('column_signature', $mapping->column_signature)
            ->where('status', ImportStatus::Profiled->value)->orderByDesc('id')->first();

        return $import instanceof Import && $mapping->isApproved() ? $this->start($mapping, $import) : null;
    }

    private function start(Mapping $mapping, Import $import): PipelineRun
    {
        PipelineRun::query()->where('feed_id', $import->feed_id)->where('status', 'awaiting_decision')
            ->update(['status' => 'done', 'failure_reason' => 'superseded', 'finished_at' => now()]);

        try {
            $run = DB::transaction(static fn (): PipelineRun => PipelineRun::query()->forceCreate([
                'feed_id' => $import->feed_id,
                'import_id' => $import->id,
                'mapping_id' => $mapping->id,
                'stage' => 'sync',
                'status' => 'queued',
            ]));
        } catch (UniqueConstraintViolationException) {
            return PipelineRun::query()->where('feed_id', $import->feed_id)->whereIn('status', PipelineRun::UNFINISHED)->firstOrFail();
        }

        // A block closure: the job is queued while the workspace context is still set.
        (static function () use ($run): void {
            dispatch(new SyncCatalogJob($run->id, $run->tenant_id));
        })();

        return $run;
    }
}
