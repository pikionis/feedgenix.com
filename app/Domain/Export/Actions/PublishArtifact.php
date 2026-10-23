<?php

declare(strict_types=1);

namespace App\Domain\Export\Actions;

use App\Domain\Export\Contracts\ArtifactServer;
use App\Domain\Export\Models\ExportArtifact;
use App\Domain\Export\Models\ExportProfile;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Go back to an earlier build of a profile: its files are served again at once (the pointer moves;
 * nothing is copied, so a channel never reads half a file). Only a build that was published and
 * still has its files.
 */
final readonly class PublishArtifact
{
    public function __construct(private ArtifactServer $server) {}

    public function handle(ExportProfile $profile, ExportArtifact $artifact): ExportProfile
    {
        $files = $artifact->files;
        if ($artifact->profile_id !== $profile->id || $artifact->status !== 'superseded' || $artifact->files_deleted || $artifact->storage_key === null
            || $files === [] || array_filter($files, fn (array $file): bool => ! $this->server->exists((string) $artifact->storage_key, $file['name'])) !== []) {
            throw new InvalidArgumentException('Only an earlier build whose files are kept can be published again.');
        }

        DB::transaction(static function () use ($profile, $artifact): void {
            $locked = ExportProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            ExportArtifact::query()->where('profile_id', $profile->id)->where('status', 'published')->update(['status' => 'superseded']);
            $artifact->forceFill(['status' => 'published', 'published_at' => now()])->save();
            $locked->forceFill(['published_artifact_id' => $artifact->id])->save();
        });

        return $profile->refresh();
    }
}
