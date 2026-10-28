<?php

declare(strict_types=1);

namespace App\Domain\Translation\Actions;

use App\Domain\Billing\Entitlements;
use App\Domain\Metering\Admission;
use App\Domain\Translation\Models\WorkUnit;
use App\Models\Tenant;

/**
 * Work that waits for the allowance, once there is room for it again (ADR 0027): a new period
 * began, or the workspace upgraded, or staff raised its limit. Its products are counted exactly as a
 * start counts them; what fits runs, a unit that only partly fits is split, the rest keeps waiting.
 * Nothing waiting is ever released without being counted.
 */
final readonly class AdmitWaitingWork
{
    public function __construct(private Admission $admission) {}

    /**
     * For the workspace in context.
     *
     * @return list<int> the runs whose units changed
     */
    public function handle(Tenant $tenant): array
    {
        if (! $this->hasRoom($tenant)) {
            return [];
        }
        $units = WorkUnit::query()->where('status', 'held')->where('hold_reason', 'allowance')->orderBy('id')->get();
        if ($units->isEmpty()) {
            return [];
        }

        $products = [];
        foreach ($units as $unit) {
            foreach (array_keys($unit->items) as $productId) {
                $products[(int) $productId] = true;
            }
        }
        $waiting = array_fill_keys($this->admission->admit($tenant, array_keys($products))['waiting'], true);

        $runs = [];
        foreach ($units as $unit) {
            $held = array_filter($unit->items, static fn (int|string $productId): bool => isset($waiting[(int) $productId]), ARRAY_FILTER_USE_KEY);
            $admitted = array_diff_key($unit->items, $held);
            if ($admitted === []) {
                continue;
            }
            if ($held !== []) {
                $unit->replicate()->forceFill(['items' => $held, 'status' => 'held', 'hold_reason' => 'allowance'])->save();
            }
            $unit->forceFill(['items' => $admitted, 'status' => 'pending', 'hold_reason' => null])->save();
            $runs[] = $unit->run_id;
        }

        return array_values(array_unique($runs));
    }

    /** Room for at least one more product this period: unlimited, overage on a paid plan, or under the allowance. */
    private function hasRoom(Tenant $tenant): bool
    {
        $entitlements = Entitlements::for($tenant);
        $allowance = $entitlements->productAllowance();

        return $allowance === null
            || ($entitlements->isPaid() && $tenant->overage_enabled)
            || $entitlements->used('products') < $allowance;
    }
}
