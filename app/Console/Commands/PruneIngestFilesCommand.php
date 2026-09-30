<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Ingest\Models\UploadSession;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Removes what abandoned uploads and crashed jobs leave on disk: expired upload sessions with
 * their chunks, job work folders untouched for a day, and staged uploads older than a day (their
 * imports expired long before). Stored feed files are never touched.
 */
#[AsCommand(name: 'ingest:prune', description: 'Delete expired uploads and leftover work files')]
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

        return self::SUCCESS;
    }

    private function report(string $what): void
    {
        $this->line(($this->dryRun ? 'would delete ' : 'deleted ').$what);
    }
}
