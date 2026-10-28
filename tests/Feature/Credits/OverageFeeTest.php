<?php

declare(strict_types=1);

use App\Domain\Credits\Actions\ChargeFee;
use App\Domain\Credits\Actions\GrantCredits;
use App\Domain\Credits\Exceptions\InsufficientCredits;
use App\Domain\Credits\Models\CreditTransaction;
use App\Domain\Notices\Models\Notice;
use App\Domain\Translation\Models\WorkUnit;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;

/* The overage fee: 10 credits a product over a paid plan's allowance (ADR 0005, ADR 0028). */

/** A Pro workspace whose allowance is 3 products; the test feed has 5. */
function overageWorkspace(int $credits = 200_000_000, bool $overage = true): array
{
    [$tenant, $feed] = translatingWorkspace(['en'], credits: $credits);
    onPro($tenant);
    $tenant->forceFill(['limits_override' => ['products_per_month' => 3], 'overage_enabled' => $overage])->save();

    return [$tenant, $feed];
}

/** @return list<int> the overage charges, in millicredits */
function overageCharges(Tenant $tenant): array
{
    return tenancy()->run($tenant->id, fn (): array => CreditTransaction::query()->where('kind', 'overage')->orderBy('id')->pluck('amount')->map(static fn (mixed $amount): int => (int) $amount)->all());
}

function overageCounted(Tenant $tenant): int
{
    return tenancy()->run($tenant->id, fn (): int => DB::table('usage_products')->where('overage', true)->count());
}

/** Products in units that wait for the credits of their overage fee. */
function waitingForFee(Tenant $tenant): int
{
    return count(array_unique(tenancy()->run($tenant->id, fn (): array => WorkUnit::query()->where('status', 'held')->where('hold_reason', 'overage_credits')
        ->get()->flatMap(fn (WorkUnit $unit): array => array_keys($unit->items))->all())));
}

it('charges the fee once, for the products over the allowance, and lets them all go ahead', function (): void {
    [$tenant, $feed] = overageWorkspace();

    $plan = startTranslation($tenant, $feed);

    expect($plan['run']['status'])->toBe('done')
        ->and(overageCounted($tenant))->toBe(2)
        ->and(overageCharges($tenant))->toBe([-20_000]);
    $charge = tenancy()->run($tenant->id, fn (): CreditTransaction => CreditTransaction::query()->where('kind', 'overage')->sole());
    expect($charge->note)->toBe("Overage: 2 products over the Pro plan's allowance (period from ".$charge->created_at->toDateString().')');

    // Started again in the same period: those products were counted, so nothing is charged again.
    startTranslation($tenant, $feed);
    expect(overageCharges($tenant))->toBe([-20_000]);
});

it('lets in only the overage the credits pay for; the rest waits and goes on once credits arrive', function (): void {
    // 15 credits: one fee of 10, and not two.
    [$tenant, $feed] = overageWorkspace(credits: 15_000);

    startTranslation($tenant, $feed);

    expect(overageCounted($tenant))->toBe(1)
        ->and(overageCharges($tenant))->toBe([-10_000])
        ->and(waitingForFee($tenant))->toBe(1)
        ->and(tenancy()->run($tenant->id, fn (): ?string => Notice::query()->where('kind', 'overage')->value('title')))->toBe('Products over the allowance wait for credits');

    tenancy()->run($tenant->id, fn () => resolve(GrantCredits::class)->handle(1_000_000));
    Artisan::call('ops:reconcile', [], new BufferedOutput);

    expect(overageCounted($tenant))->toBe(2)
        ->and(overageCharges($tenant))->toBe([-10_000, -10_000])
        ->and(waitingForFee($tenant))->toBe(0);
});

it('never lets work that waits for its fee run uncounted, however long it waits', function (): void {
    [$tenant, $feed] = overageWorkspace(credits: 15_000);
    startTranslation($tenant, $feed);

    // Long past the reconciler's five-minute retry of the holds it may release by itself.
    $this->travel(10)->minutes();
    Artisan::call('ops:reconcile', [], new BufferedOutput);

    expect(waitingForFee($tenant))->toBe(1)
        ->and(overageCounted($tenant))->toBe(1)
        ->and(overageCharges($tenant))->toBe([-10_000]);
});

it('never takes the fee from credits held for work that is running', function (): void {
    [$tenant, $feed] = overageWorkspace(credits: 30_000);
    // 25 of the 30 credits are held by a reservation: 5 are available, less than one fee.
    tenancy()->run($tenant->id, fn () => DB::table('credit_wallets')->update(['reserved' => 25_000]));

    startTranslation($tenant, $feed);

    expect(overageCharges($tenant))->toBe([])
        ->and(overageCounted($tenant))->toBe(0)
        ->and(waitingForFee($tenant))->toBe(2)
        ->and(tenancy()->run($tenant->id, fn (): int => (int) DB::table('credit_wallets')->value('balance')))->toBe(30_000);
});

it('waits for the allowance, and charges nothing, when overage is off', function (): void {
    [$tenant, $feed] = overageWorkspace(overage: false);

    startTranslation($tenant, $feed);

    expect(overageCharges($tenant))->toBe([])
        ->and(overageCounted($tenant))->toBe(0)
        ->and(waitingForFee($tenant))->toBe(0)
        ->and(tenancy()->run($tenant->id, fn (): int => WorkUnit::query()->where('hold_reason', 'allowance')->count()))->toBeGreaterThan(0);
});

it('never charges a fee for a product counted without one: Free waits, it does not pay', function (): void {
    [$tenant, $feed] = translatingWorkspace(['en']);
    $tenant->forceFill(['limits_override' => ['products_per_month' => 3]])->save();

    startTranslation($tenant, $feed);

    expect(overageCharges($tenant))->toBe([])->and(overageCounted($tenant))->toBe(0);
});

it('takes a fee all or nothing, only as overage, and never below one millicredit', function (): void {
    $tenant = Tenant::factory()->create();
    tenancy()->run($tenant->id, fn () => resolve(GrantCredits::class)->handle(5_000));
    $charge = fn (int $millicredits, string $kind = 'overage') => tenancy()->run($tenant->id, fn () => resolve(ChargeFee::class)->handle($millicredits, $kind, 'test'));

    expect(fn () => $charge(6_000))->toThrow(InsufficientCredits::class)
        ->and(fn () => $charge(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $charge(1_000, 'ai_usage'))->toThrow(InvalidArgumentException::class)
        ->and(walletOf($tenant)?->balance)->toBe(5_000);

    $charge(5_000);
    expect(walletOf($tenant)?->balance)->toBe(0);
});
