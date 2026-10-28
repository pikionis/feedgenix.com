<?php

declare(strict_types=1);

namespace App\Domain\Translation\Actions;

use App\Domain\Metering\Admission;
use App\Domain\Translation\Models\WorkUnit;
use App\Models\Tenant;

/**
 * Work that waits for the allowance or for the credits of its overage fee, once there is room for
 * it again (ADR 0027, ADR 0028): a new period began, the workspace upgraded, staff raised its limit,
 * or credits arrived. Its products are counted (and their fee charged) exactly as a start does; what
 * fits runs, a unit that only partly fits is split, the rest keeps waiting. Nothing waiting is ever
 * released without being counted.
 */
final readonly class AdmitWaitingWork
{
    /** The hold reasons only an admission may release: never retried blindly by the reconciler. */
    public const array REASONS = ['allowance', 'overage_credits'];

    public function __construct(private Admission $admission) {}

    /** @param 'allowance'|'credits' $waitingFor */
    public static function holdReason(string $waitingFor): string
    {
        return $waitingFor === 'credits' ? 'overage_credits' : 'allowance';
    }

    /**
     * For the workspace in context.
     *
     * @return list<int> the runs whose units changed
     */
    public function handle(Tenant $tenant): array
    {
        if (! $this->admission->hasRoom($tenant)) {
            return [];
        }
        $units = WorkUnit::query()->where('status', 'held')->whereIn('hold_reason', self::REASONS)->orderBy('id')->get();
        if ($units->isEmpty()) {
            return [];
        }

        $products = [];
        foreach ($units as $unit) {
            foreach (array_keys($unit->items) as $productId) {
                $products[(int) $productId] = true;
            }
        }
        $admission = $this->admission->admit($tenant, array_keys($products));
        $waiting = array_fill_keys($admission['waiting'], true);
        $reason = self::holdReason($admission['waiting_for']);

        $runs = [];
        foreach ($units as $unit) {
            $held = array_filter($unit->items, static fn (int|string $productId): bool => isset($waiting[(int) $productId]), ARRAY_FILTER_USE_KEY);
            $admitted = array_diff_key($unit->items, $held);
            if ($admitted === []) {
                $unit->forceFill(['hold_reason' => $reason])->save();

                continue;
            }
            if ($held !== []) {
                $unit->replicate()->forceFill(['items' => $held, 'status' => 'held', 'hold_reason' => $reason])->save();
            }
            $unit->forceFill(['items' => $admitted, 'status' => 'pending', 'hold_reason' => null])->save();
            $runs[] = $unit->run_id;
        }

        return array_values(array_unique($runs));
    }
}
