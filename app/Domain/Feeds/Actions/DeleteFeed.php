<?php

declare(strict_types=1);

namespace App\Domain\Feeds\Actions;

use App\Domain\Feeds\Models\Feed;
use App\Domain\Ingest\Models\Import;
use App\Domain\Ingest\Models\UploadSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a feed with its imports, profiles and uploads. Stored files are removed unless another
 * feed of the workspace holds the same content.
 */
final class DeleteFeed
{
    public function handle(Feed $feed): void
    {
        $blobs = Import::query()->where('feed_id', $feed->id)->whereNotNull('blob_path')->distinct()->pluck('blob_path')->all();
        $uploads = UploadSession::query()->where('feed_id', $feed->id)->get()->map(static fn (UploadSession $session): string => $session->directory())->all();
        $staged = Import::query()->where('feed_id', $feed->id)->whereNotNull('staging_path')->pluck('staging_path')->all();

        DB::transaction(static fn (): ?bool => $feed->delete());

        $stillUsed = Import::query()->whereIn('blob_path', $blobs)->pluck('blob_path')->all();
        $disk = Storage::disk('local');
        $disk->delete([...array_diff($blobs, $stillUsed), ...$staged]);
        foreach ($uploads as $directory) {
            $disk->deleteDirectory($directory);
        }
    }
}
