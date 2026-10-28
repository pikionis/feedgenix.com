<?php

declare(strict_types=1);

namespace App\Domain\Metering;

use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanCatalog;
use App\Domain\Billing\TenantEntitlements;
use App\Domain\Credits\Actions\ChargeFee;
use App\Domain\Credits\Millicredits;
use App\Domain\Credits\Models\CreditWallet;
use App\Domain\Notices\Notify;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Counts the products a run will work on against the plan's allowance (ADR 0005, ADR 0027): a
 * product counts once per period, however often it is translated. The products that fit (and those
 * already counted this period) go ahead. Above the allowance, a paid plan with overage on goes ahead
 * for as many products as the available credits pay the overage fee of (ADR 0028), charged with the
 * count in one transaction; otherwise the products over it wait, uncounted, and are admitted again
 * once there is room. Nothing is ever worked on that was not counted, or counted twice.
 *
 * The workspace is told (ADR 0025) when its usage passes 80% of the allowance, and when products
 * wait for the next period, with what it can do about it.
 */
final readonly class Admission
{
    private const float HEADS_UP = 0.8;

    public function __construct(private ChargeFee $fee) {}

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
     * left of the allowance; with overage on, the next ones go ahead as far as the available credits
     * pay their fee. `waiting` lists the rest, and `waiting_for` why: the allowance or credits.
     *
     * @param  list<int>  $productIds  in the order the work was planned
     * @return array{held: bool, new: int, over: int, allowance: int|null, used: int, resets_at: string, waiting: list<int>, waiting_for: 'allowance'|'credits'}
     */
    public function admit(Tenant $tenant, array $productIds): array
    {
        $entitlements = Entitlements::for($tenant);
        [$result, $new, $start] = $this->count($entitlements, $productIds);
        $overage = $this->overageAllowed($entitlements, $tenant);
        $fits = count($new) - $result['over'];
        $fee = $overage ? (int) PlanCatalog::overageCreditsPerProduct($entitlements->plan()) * Millicredits::PER_CREDIT : 0;

        $paid = $this->record($tenant, $entitlements, $start, array_slice($new, 0, $fits), $overage ? array_slice($new, $fits) : [], $fee);
        $waiting = array_slice($new, $fits + $paid);
        $waitingFor = $overage ? 'credits' : 'allowance';

        $this->tellUsage($tenant, $entitlements, $result, $start);
        if ($waiting !== []) {
            $waitingFor === 'credits'
                ? $this->tellOverageHeld($tenant, $entitlements, count($waiting), $fee, $start)
                : $this->tellHeld($tenant, $entitlements, $result, $start);
        }

        return ['held' => $waiting !== [], ...$result, 'waiting' => $waiting, 'waiting_for' => $waitingFor];
    }

    /**
     * Count the products that fit, and as many overage products as the available credits pay the
     * fee of, and charge that fee, in one transaction. The wallet row is locked first, so what is
     * available cannot change between counting and charging; a fee is charged only for products
     * this call counted, so never twice.
     *
     * @param  list<int>  $fitting
     * @param  list<int>  $overage  over the allowance, overage on
     * @return int how many of the overage products go ahead
     */
    private function record(Tenant $tenant, TenantEntitlements $entitlements, string $start, array $fitting, array $overage, int $fee): int
    {
        return DB::transaction(function () use ($tenant, $entitlements, $start, $fitting, $overage, $fee): int {
            $this->insert($tenant, $start, $fitting, false);
            if ($overage === [] || $fee === 0) {
                $this->insert($tenant, $start, $overage, true);

                return count($overage);
            }

            $available = (int) DB::scalar('SELECT balance - reserved FROM credit_wallets WHERE tenant_id = ? FOR UPDATE', [$tenant->id], false);
            $paid = min(count($overage), intdiv(max(0, $available), $fee));
            $counted = $this->insert($tenant, $start, array_slice($overage, 0, $paid), true);
            if ($counted > 0) {
                $this->fee->handle($counted * $fee, 'overage', sprintf(
                    'Overage: %s %s over the %s plan\'s allowance (period from %s)',
                    number_format($counted), $counted === 1 ? 'product' : 'products', PlanCatalog::name($entitlements->plan()), $start,
                ));
            }

            return $paid;
        });
    }

    /**
     * @param  list<int>  $productIds
     * @return int how many were counted now (not already counted this period)
     */
    private function insert(Tenant $tenant, string $start, array $productIds, bool $overage): int
    {
        $now = now();
        $counted = 0;
        foreach (array_chunk($productIds, 1_000) as $chunk) {
            $counted += DB::table('usage_products')->insertOrIgnore(array_map(static fn (int $productId): array => [
                'tenant_id' => $tenant->id,
                'period_start' => $start,
                'product_id' => $productId,
                'overage' => $overage,
                'created_at' => $now,
            ], $chunk));
        }

        return $counted;
    }

    /**
     * Room for at least one more product this period, for the workspace in context: unlimited,
     * under the allowance, or overage on a paid plan with the credits for one more fee.
     */
    public function hasRoom(Tenant $tenant): bool
    {
        $entitlements = Entitlements::for($tenant);
        $allowance = $entitlements->productAllowance();
        if ($allowance === null || $entitlements->used('products') < $allowance) {
            return true;
        }
        if (! $this->overageAllowed($entitlements, $tenant)) {
            return false;
        }
        $fee = (int) PlanCatalog::overageCreditsPerProduct($entitlements->plan()) * Millicredits::PER_CREDIT;

        return $this->available() >= $fee;
    }

    /** Credits the workspace in context can spend now: the balance less what running work holds. */
    private function available(): int
    {
        return (int) (CreditWallet::query()->first()?->available() ?? 0);
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
        $after = min($result['used'] + $result['new'], $allowance ?? PHP_INT_MAX);
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

    private function tellOverageHeld(Tenant $tenant, TenantEntitlements $entitlements, int $waiting, int $fee, string $start): void
    {
        $plan = PlanCatalog::name($entitlements->plan());
        $each = intdiv($fee, Millicredits::PER_CREDIT);
        Notify::send($tenant->id, 'overage', "overage_held:{$start}", 'action', 'Products over the allowance wait for credits',
            "{$this->number($waiting)} ".($waiting === 1 ? 'product is' : 'products are')." over the products your {$plan} plan includes this period. Each is {$this->number($each)} credits as overage, and the workspace does not hold enough credits for them. They continue by themselves once there are credits.",
            'See billing', '/app/billing', email: true);
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
