<?php

declare(strict_types=1);

namespace App\Domain\Translation\Jobs;

use App\Domain\Tenancy\TenantContext;
use App\Domain\Translation\Models\WorkUnit;
use App\Domain\Translation\RunProgress;
use App\Domain\Translation\UnitExecutor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * Carries one leased work unit to a worker. The database decides what runs (ADR 0006): a job whose
 * lease was taken back does nothing, and a crash leaves the unit to the reconciler, which hands it
 * out again when the lease expires.
 */
#[Timeout(self::TIMEOUT)]
#[Tries(1)]
final class TranslateUnitJob implements ShouldQueue
{
    use Queueable;

    // Within the lease: four attempts of three minutes and a fallback fit in fifteen.
    public const int TIMEOUT = 840;

    public function __construct(
        public readonly int $unitId,
        public readonly int $tenantId,
        public readonly string $leaseToken,
    ) {}

    public function handle(UnitExecutor $executor): void
    {
        $executor->run($this->unitId, $this->leaseToken);
    }

    /** Crashed or timed out: give the unit back at once rather than waiting for the lease. */
    public function failed(?Throwable $exception): void
    {
        resolve(TenantContext::class)->run($this->tenantId, function (): void {
            $unit = WorkUnit::query()->whereKey($this->unitId)->where('lease_token', $this->leaseToken)->where('status', 'leased')->first();
            if ($unit instanceof WorkUnit) {
                $unit->forceFill(['status' => $unit->attempts >= UnitExecutor::MAX_ATTEMPTS ? 'failed' : 'pending', 'failure_reason' => 'interrupted', 'lease_token' => null, 'lease_until' => null, 'available_at' => now()->addMinute()])->save();
                RunProgress::refresh($unit->run_id);
            }
        });
    }
}
