<?php

declare(strict_types=1);

namespace App\Domain\Metering;

use App\Domain\Billing\Entitlements;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Counts the products a run will work on against the plan's allowance (ADR 0005): a product counts
 * once per period, however often it is translated. Within the allowance, or above it on a paid plan
 * with overage on, the run goes ahead (the overage products are flagged; their fee is charged with
 * the wallet, M7). Otherwise nothing is counted and the work waits, with the numbers.
 */
final class Admission
{
    /**
     * @param  list<int>  $productIds
     * @return array{held: bool, new: int, over: int, allowance: int|null, used: int, resets_at: string}
     */
    public function admit(Tenant $tenant, array $productIds): array
    {
        $entitlements = Entitlements::for($tenant);
        $period = $entitlements->period();
        $start = $period['start']->toDateString();
        $allowance = $entitlements->productAllowance();

        $known = [];
        foreach (array_chunk($productIds, 5_000) as $chunk) {
            $known = [...$known, ...DB::table('usage_products')->where('period_start', $start)->whereIn('product_id', $chunk)->pluck('product_id')->map(static fn (mixed $value): int => (int) $value)->all()];
        }
        $new = array_values(array_diff($productIds, $known));
        $used = DB::table('usage_products')->where('period_start', $start)->count();
        $over = $allowance === null ? 0 : max(0, count($new) - max(0, $allowance - $used));
        $result = ['new' => count($new), 'over' => $over, 'allowance' => $allowance, 'used' => $used, 'resets_at' => $period['end']->toIso8601String()];

        if ($over > 0 && (! $entitlements->isPaid() || ! $tenant->overage_enabled)) {
            return ['held' => true, ...$result];
        }

        $now = now();
        $firstOver = count($new) - $over;
        foreach (array_chunk($new, 1_000, true) as $chunk) {
            DB::table('usage_products')->insertOrIgnore(array_map(static fn (int $productId, int $index): array => [
                'tenant_id' => $tenant->id,
                'period_start' => $start,
                'product_id' => $productId,
                'overage' => $index >= $firstOver,
                'created_at' => $now,
            ], $chunk, array_keys($chunk)));
        }

        return ['held' => false, ...$result];
    }
}
