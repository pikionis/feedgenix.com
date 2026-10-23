<?php

declare(strict_types=1);

namespace App\Domain\Export\Jobs;

use App\Domain\Export\Exceptions\ExportBusy;
use App\Domain\Export\ExportBuilder;
use App\Domain\Export\Models\ExportArtifact;
use App\Domain\Export\Models\ExportProfile;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\Attributes\UniqueFor;
use Throwable;

/**
 * Builds one export profile, as the workspace that queued it. One attempt: a failed build is a
 * record on the profile, and the reconciler builds again when the catalogue next changes. Queued
 * once per profile at a time, however often the reconciler asks.
 */
#[Timeout(self::TIMEOUT)]
#[Tries(1)]
#[UniqueFor(self::TIMEOUT)]
final class BuildExportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public const int TIMEOUT = 10_800;

    public function __construct(
        public readonly int $profileId,
        public readonly int $tenantId,
        public readonly bool $allowShrink = false,
    ) {}

    public function uniqueId(): string
    {
        return $this->profileId.($this->allowShrink ? ':shrink' : '');
    }

    public function handle(ExportBuilder $builder): void
    {
        $profile = ExportProfile::query()->find($this->profileId);
        if (! $profile instanceof ExportProfile) {
            return;
        }
        try {
            $builder->build($profile, $this->allowShrink);
        } catch (ExportBusy) {
            // Another build of it runs: that one publishes what there is.
        }
    }

    /** Killed or timed out: its build must not stay "building" (it would block every next one). */
    public function failed(?Throwable $exception): void
    {
        resolve(TenantContext::class)->run($this->tenantId, function (): void {
            ExportArtifact::query()->where('profile_id', $this->profileId)->where('status', 'building')
                ->update(['status' => 'failed', 'failure_reason' => 'interrupted', 'failure_message' => 'The build stopped unexpectedly. It runs again when the catalogue next changes, or when you build it now.']);
        });
    }
}
