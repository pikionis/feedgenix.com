<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Actions;

use App\Domain\Feeds\Models\Feed;
use App\Domain\Ingest\Exceptions\FeedBusy;
use App\Domain\Ingest\ImportStatus;
use App\Domain\Ingest\Jobs\ProcessImport;
use App\Domain\Ingest\Models\Import;
use App\Domain\Ingest\ParseOptions;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Starts an import of a feed and queues its work, as the current workspace. At most one import
 * per feed is unfinished (a partial unique index); a second request gets FeedBusy (409).
 */
final class QueueImport
{
    /** An unfinished import older than this outlived any job that could run it: it is over. */
    private const int STALE_AFTER_SECONDS = 7_200;

    public function fetch(Feed $feed): Import
    {
        if ($feed->source !== 'url') {
            throw new InvalidArgumentException('Only URL feeds are fetched.');
        }

        return $this->queue($feed, ['origin' => 'fetch']);
    }

    public function upload(Feed $feed, string $stagingPath, string $originalName): Import
    {
        return $this->queue($feed, ['origin' => 'upload', 'staging_path' => $stagingPath, 'original_name' => $originalName]);
    }

    /** Reads an import's stored file again, with new options (the customer corrected the encoding…). */
    public function reread(Import $source, ParseOptions $options): Import
    {
        $feed = $source->feed;
        $feed->forceFill(['parse_options' => $options->toArray()])->save();

        return $this->queue($feed, ['origin' => 'reread', 'reuses_import_id' => $source->id, 'original_name' => $source->original_name]);
    }

    /** @param array<string, mixed> $attributes */
    private function queue(Feed $feed, array $attributes): Import
    {
        $this->expireStale($feed);

        try {
            // A savepoint, so a lost race leaves the caller's transaction usable.
            $import = DB::transaction(static fn (): Import => Import::query()->create([
                ...$attributes,
                'feed_id' => $feed->id,
                'status' => ImportStatus::Queued,
                'parse_options' => $feed->parseOptions()->toArray(),
            ]));
        } catch (UniqueConstraintViolationException) {
            throw new FeedBusy;
        }

        dispatch(new ProcessImport($import->id, $import->tenant_id))->afterCommit();

        return $import;
    }

    private function expireStale(Feed $feed): void
    {
        Import::query()
            ->where('feed_id', $feed->id)
            ->whereIn('status', [ImportStatus::Queued, ImportStatus::Fetching, ImportStatus::Reading])
            ->where('updated_at', '<', now()->subSeconds(self::STALE_AFTER_SECONDS))
            ->update([
                'status' => ImportStatus::Failed,
                'failure_reason' => 'interrupted',
                'failure_message' => 'The import stopped unexpectedly and was abandoned.',
                'finished_at' => now(),
            ]);
    }
}
