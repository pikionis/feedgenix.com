<?php

declare(strict_types=1);

use App\Domain\Translation\Models\WorkUnit;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;

/* Work held for the allowance, when the next period begins (ADR 0005, ADR 0027). */

/** Products counted per period start, in order. */
function countedByPeriod(Tenant $tenant): array
{
    return tenancy()->run($tenant->id, fn (): array => DB::table('usage_products')->selectRaw('period_start, count(*) AS n')
        ->groupBy('period_start')->orderBy('period_start')->pluck('n', 'period_start')->map(static fn (mixed $count): int => (int) $count)->all());
}

/** Products whose title the AI translated ("keep" fields are written for every product, at no cost). */
function translatedProducts(Tenant $tenant): int
{
    return tenancy()->run($tenant->id, fn (): int => DB::table('product_texts')->where('role', 'target')->where('locale', 'en')
        ->where('field', 'title')->where('origin', 'ai')->distinct()->count('product_id'));
}

/** Products in units that wait for the allowance. */
function waitingProducts(Tenant $tenant): int
{
    return count(array_unique(tenancy()->run($tenant->id, fn (): array => WorkUnit::query()->where('status', 'held')->where('hold_reason', 'allowance')
        ->get()->flatMap(fn (WorkUnit $unit): array => array_keys($unit->items))->all())));
}

function reconcile(): void
{
    Artisan::call('ops:reconcile', [], new BufferedOutput);
}

it('counts what waited against the new period before translating it', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $tenant->forceFill(['limits_override' => ['products_per_month' => 3]])->save();

    startTranslation($tenant, $feed);
    expect(translatedProducts($tenant))->toBe(3)->and(array_values(countedByPeriod($tenant)))->toBe([3])->and(waitingProducts($tenant))->toBe(2);

    // Before ADR 0027 the reconciler released the waiting units uncounted: 5 translated, 3 counted.
    $this->travel(32)->days();
    reconcile();

    expect(translatedProducts($tenant))->toBe(5)
        ->and(array_values(countedByPeriod($tenant)))->toBe([3, 2])
        ->and(waitingProducts($tenant))->toBe(0)
        ->and(latestRun($tenant, $feed)->status)->toBe('done');
});

it('lets in only what fits each period, splitting a batch that partly fits', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $tenant->forceFill(['limits_override' => ['products_per_month' => 2]])->save();

    startTranslation($tenant, $feed);
    expect(translatedProducts($tenant))->toBe(2);

    $this->travel(32)->days();
    reconcile();
    expect(translatedProducts($tenant))->toBe(4)
        ->and(array_values(countedByPeriod($tenant)))->toBe([2, 2])
        ->and(waitingProducts($tenant))->toBe(1)
        ->and(latestRun($tenant, $feed)->status)->toBe('held');

    $this->travel(31)->days();
    reconcile();
    expect(translatedProducts($tenant))->toBe(5)
        ->and(array_values(countedByPeriod($tenant)))->toBe([2, 2, 1])
        ->and(latestRun($tenant, $feed)->status)->toBe('done');
});

it('lets nothing waiting run in the period it was held in', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $tenant->forceFill(['limits_override' => ['products_per_month' => 3]])->save();
    startTranslation($tenant, $feed);

    $this->travel(10)->days();
    reconcile();

    expect(translatedProducts($tenant))->toBe(3)
        ->and(array_values(countedByPeriod($tenant)))->toBe([3])
        ->and(waitingProducts($tenant))->toBe(2);
});

it('lets a workspace that upgraded take what waited at once', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $tenant->forceFill(['limits_override' => ['products_per_month' => 3]])->save();
    startTranslation($tenant, $feed);

    $this->travel(1)->minutes();
    $tenant->forceFill(['limits_override' => null])->save();
    onPro($tenant);
    // Upgrading starts a new term, so a new period: the reconciler admits what waited.
    reconcile();

    expect(translatedProducts($tenant))->toBe(5);
});
