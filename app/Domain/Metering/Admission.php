<?php

declare(strict_types=1);

namespace App\Domain\Metering;

use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanCatalog;
use App\Domain\Billing\TenantEntitlements;
use App\Domain\Notices\Notify;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Counts the products a run will work on against the plan's allowance (ADR 0005, ADR 0027): a
 * product counts once per period, however often it is translated. The products that fit (and those
 * already counted this period) go ahead; above the allowance, a paid plan with overage on goes ahead
 * too (the overage products are flagged; their fee is charged with the wallet, M7), otherwise the
 * products over it wait, uncounted, and are admitted again when the next period begins. Nothing is
 * ever worked on that was not counted.
 *
 * The workspace is told (ADR 0025) when its usage passes 80% of the allowance, and when products
 * wait for the next period, with what it can do about it.
 */
final class Admission
{
    private const float HEADS_UP = 0.8;

    /**
     * What admitting these products would do, without counting them: for the plan page before a run.
     *
     * @param  list<int>  $productIds
     * @return array{held: bool, new: int, over: int, allowance: int|null, used: int, resets_at: string}
     */
    public function preview(Tenant $tenant, array $productIds): array
    {
        $entitlements = Entitlements::for($tenant);
        [$result] = $this->count($entitlements, $productIds);

        return ['held' => $result['over'] > 0 && ! $this->overageAllowed($entitlements, $tenant), ...$result];
    }

    /**
     * Count what fits and say what waits: the first new products in the order given fill what is
     * left of the allowance; `waiting` lists the ones over it (empty with overage on).
     *
     * @param  list<int>  $productIds  in the order the work was planned
     * @return array{held: bool, new: int, over: int, allowance: int|null, used: int, resets_at: string, waiting: list<int>}
     */
    public function admit(Tenant $tenant, array $productIds): array
    {
        $entitlements = Entitlements::for($tenant);
        [$result, $new, $start] = $this->count($entitlements, $productIds);
        $overage = $this->overageAllowed($entitlements, $tenant);
        $fits = count($new) - $result['over'];
        $counted = $overage ? $new : array_slice($new, 0, $fits);
        $waiting = $overage ? [] : array_slice($new, $fits);

        $now = now();
        foreach (array_chunk($counted, 1_000, true) as $chunk) {
            DB::table('usage_products')->insertOrIgnore(array_map(static fn (int $productId, int $index): array => [
                'tenant_id' => $tenant->id,
                'period_start' => $start,
                'product_id' => $productId,
                'overage' => $index >= $fits,
                'created_at' => $now,
            ], $chunk, array_keys($chunk)));
        }
        $this->tellUsage($tenant, $entitlements, $result, $start);
        if ($waiting !== []) {
            $this->tellHeld($tenant, $entitlements, $result, $start);
        }

        return ['held' => $waiting !== [], ...$result, 'waiting' => $waiting];
    }

    /**
     * @param  list<int>  $productIds
     * @return array{0: array{new: int, over: int, allowance: int|null, used: int, resets_at: string}, 1: list<int>, 2: string}
     */
    private function count(TenantEntitlements $entitlements, array $productIds): array
    {
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

        return [['new' => count($new), 'over' => $over, 'allowance' => $allowance, 'used' => $used, 'resets_at' => $period['end']->toIso8601String()], $new, $start];
    }

    private function overageAllowed(TenantEntitlements $entitlements, Tenant $tenant): bool
    {
        return $entitlements->isPaid() && $tenant->overage_enabled;
    }

    /** @param array{new: int, over: int, allowance: int|null, used: int, resets_at: string} $result */
    private function tellUsage(Tenant $tenant, TenantEntitlements $entitlements, array $result, string $start): void
    {
        $allowance = $result['allowance'];
        $after = $result['used'] + $result['new'] - ($tenant->overage_enabled && $entitlements->isPaid() ? 0 : $result['over']);
        // Once a period (the notice's key): the first admission that reaches 80%.
        if ($allowance === null || $allowance === 0 || $after < $allowance * self::HEADS_UP) {
            return;
        }
        $plan = PlanCatalog::name($entitlements->plan());
        $credits = PlanCatalog::overageCreditsPerProduct($entitlements->plan());
        $then = match (true) {
            ! $entitlements->isPaid() => 'After that, new products wait until then. Upgrade to translate more this period.',
            $tenant->overage_enabled && $credits !== null => 'After that, each new product is '.number_format($credits).' credits.',
            default => 'After that, new products wait until then.',
        };

        Notify::send($tenant->id, 'usage', "usage_80:{$start}", 'warn', '80% of this period\'s products used',
            "Your workspace has used {$this->number(min($after, $allowance))} of the {$this->number($allowance)} products its {$plan} plan includes until {$this->date($result['resets_at'])}. {$then}",
            'See the plans', '/app/billing/plans', email: true);
    }

    /** @param array{new: int, over: int, allowance: int|null, used: int, resets_at: string} $result */
    private function tellHeld(Tenant $tenant, TenantEntitlements $entitlements, array $result, string $start): void
    {
        $plan = PlanCatalog::name($entitlements->plan());
        Notify::send($tenant->id, 'allowance', "allowance_held:{$start}", 'action', 'Products wait for the next period',
            "{$this->number($result['over'])} ".($result['over'] === 1 ? 'product is' : 'products are')." over the {$this->number((int) $result['allowance'])} products your {$plan} plan includes until {$this->date($result['resets_at'])}. The others are translated now; these start by themselves then, or at once when you upgrade.",
            'See the plans', '/app/billing/plans', email: true);
    }

    private function number(int $value): string
    {
        return number_format($value);
    }

    private function date(string $iso): string
    {
        return substr($iso, 0, 10);
    }
}
