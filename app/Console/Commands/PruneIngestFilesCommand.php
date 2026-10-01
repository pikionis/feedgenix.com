<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Feeds\Models\Feed;
use App\Domain\Ingest\ImportStatus;
use App\Domain\Ingest\Models\Import;
use App\Domain\Ingest\Models\UploadSession;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Keeps the disk to what is needed:
 * - expired upload sessions with their chunks;
 * - job work folders untouched for a day, and staged uploads older than a day (their imports
 *   expired long before);
 * - stored feed files beyond the last few of each feed (config ingest.keep_files_per_feed), never
 *   the one the feed's current profile was read from, nor a file another import still uses.
 */
#[AsCommand(name: 'ingest:prune', description: 'Delete expired uploads, leftover work files and old feed files')]
#[Signature('ingest:prune {--dry-run : List what would be deleted}')]
final class PruneIngestFilesCommand extends Command
{
    private const int DAY = 86_400;

    private bool $dryRun = false;

    public function handle(TenantContext $tenancy): int
    {
        $this->dryRun = (bool) $this->option('dry-run');
        $disk = Storage::disk('local');

        $sessions = $tenancy->runAsSystem('prune expired uploads', static fn () => UploadSession::query()
            ->where('status', 'open')
            ->where('expires_at', '<', now())
            ->get());

        foreach ($sessions as $session) {
            $this->report('upload '.$session->id);
            if (! $this->dryRun) {
                $disk->deleteDirectory($session->directory());
                $tenancy->runAsSystem('prune expired uploads', static fn (): ?bool => $session->delete());
            }
        }

        $cutoff = time() - self::DAY;

        // One folder per job run: gone only when nothing inside changed for a day.
        foreach ($disk->directories('work') as $folder) {
            $newest = max([$disk->lastModified($folder), ...array_map($disk->lastModified(...), $disk->allFiles($folder))]);
            if ($newest < $cutoff) {
                $this->report($folder);
                if (! $this->dryRun) {
                    $disk->deleteDirectory($folder);
                }
            }
        }

        // Files only: the per-workspace folders stay, an upload may be completing into one.
        foreach ($disk->allFiles('staging') as $file) {
            if ($disk->lastModified($file) < $cutoff) {
                $this->report($file);
                if (! $this->dryRun) {
                    $disk->delete($file);
                }
            }
        }

        $tenancy->runAsSystem('prune old feed files', fn () => Feed::query()->select('id')->chunkById(200, function ($feeds) use ($disk): void {
            foreach ($feeds as $feed) {
                $this->pruneFeedFiles($disk, $feed->id);
            }
        }));

        return self::SUCCESS;
    }

    /** Forgets the stored file of each import beyond the feed's last few, deleting it once nothing uses it. */
    private function pruneFeedFiles(Filesystem $disk, int $feedId): void
    {
        $keep = max(1, (int) config('ingest.keep_files_per_feed'));
        $profile = Import::query()->where('feed_id', $feedId)->where('status', ImportStatus::Profiled)->max('id');
        $profileId = is_numeric($profile) ? (int) $profile : null;

        $old = Import::query()
            ->select(['id', 'blob_path'])
            ->where('feed_id', $feedId)
            ->whereNotNull('blob_path')
            ->orderByDesc('id')
            ->skip($keep)
            ->get()
            ->reject(static fn (Import $import): bool => $import->id === $profileId);

        foreach ($old as $import) {
            $blob = (string) $import->blob_path;
            $this->report("file of import {$import->id}");
            if ($this->dryRun) {
                continue;
            }

            Import::query()->whereKey($import->id)->update(['blob_path' => null]);
            if (! Import::query()->where('blob_path', $blob)->exists()) {
                $disk->delete($blob);
            }
        }
    }

    private function report(string $what): void
    {
        $this->line(($this->dryRun ? 'would delete ' : 'deleted ').$what);
    }
}
