<?php

declare(strict_types=1);

namespace App\Domain\Export\Actions;

use App\Domain\Export\Jobs\BuildExportJob;
use App\Domain\Export\Models\ExportProfile;

/** Queue a build of a profile, as its workspace. */
final class QueueExportBuild
{
    public function handle(ExportProfile $profile, bool $allowShrink = false): void
    {
        // A block closure: the job is queued while the workspace context is still set.
        (static function () use ($profile, $allowShrink): void {
            dispatch(new BuildExportJob($profile->id, $profile->tenant_id, $allowShrink));
        })();
    }
}
