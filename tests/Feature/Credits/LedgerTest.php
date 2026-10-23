<?php

declare(strict_types=1);

use App\Domain\Credits\Actions\ConsumeReservation;
use App\Domain\Credits\Actions\GrantCredits;
use App\Domain\Credits\Actions\ReserveCredits;
use App\Domain\Credits\Actions\SettleReservation;
use App\Domain\Credits\Exceptions\InsufficientCredits;
use App\Domain\Credits\Models\CreditReservation;
use App\Domain\Credits\Models\CreditTransaction;
use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/*
 * The credit ledger (ADR 0005, ADR 0016): integer millicredits, conditional UPDATEs, CHECK
 * constraints underneath, one ai_usage transaction per settled reservation. Legacy D14: spend was a
 * cache read-modify-write that lost updates.
 */

function asWorkspace(Tenant $tenant, callable $callback): mixed
{
    return tenancy()->run($tenant->id, $callback);
}

/** @return list<CreditTransaction> */
function transactionsOf(Tenant $tenant): array
{
    return asWorkspace($tenant, static fn (): array => CreditTransaction::query()->orderBy('id')->get()->all());
}

describe('grants', function (): void {
    it('opens the wallet on the first grant and adds every later one, with the balance after each', function (): void {
        $tenant = Tenant::factory()->create();

        asWorkspace($tenant, function (): void {
            resolve(GrantCredits::class)->handle(5_000, 'grant', 'beta budget');
            resolve(GrantCredits::class)->handle(2_500);
        });

        expect(walletOf($tenant)?->balance)->toBe(7_500)
            ->and(array_map(static fn (CreditTransaction $t): array => [$t->kind, $t->amount, $t->balance_after, $t->note], transactionsOf($tenant)))
            ->toBe([['grant', 5_000, 5_000, 'beta budget'], ['grant', 2_500, 7_500, null]]);
    });

    it('refuses a grant that adds nothing or is not a credit-adding kind', function (int $amount, string $kind): void {
        $tenant = Tenant::factory()->create();

        expect(fn (): mixed => asWorkspace($tenant, fn () => resolve(GrantCredits::class)->handle($amount, $kind)))
            ->toThrow(InvalidArgumentException::class);
        expect(walletOf($tenant))->toBeNull();
    })->with([
        'zero' => [0, 'grant'],
        'negative' => [-100, 'grant'],
        'usage disguised as a grant' => [100, 'ai_usage'],
    ]);

    it('needs a workspace context', function (): void {
        expect(fn () => resolve(GrantCredits::class)->handle(100))->toThrow(MissingTenantContext::class);
    });
});

describe('reservations', function (): void {
    it('holds credits without spending them', function (): void {
        $tenant = Tenant::factory()->create();
        $reservation = reserveFor($tenant, 3_000, grant: 10_000);

        $wallet = walletOf($tenant);
        expect($wallet?->balance)->toBe(10_000)
            ->and($wallet?->reserved)->toBe(3_000)
            ->and($wallet?->available())->toBe(7_000)
            ->and($reservation->status)->toBe('open')
            ->and($reservation->expires_at->greaterThan(now()->addMinutes(59)))->toBeTrue();
    });

    it('refuses to hold more than is available, with the exact numbers, and changes nothing', function (): void {
        $tenant = Tenant::factory()->create();
        reserveFor($tenant, 8_000, grant: 10_000);

        $failure = null;
        try {
            asWorkspace($tenant, fn () => resolve(ReserveCredits::class)->handle('unit-2', 2_001));
        } catch (InsufficientCredits $insufficient) {
            $failure = $insufficient;
        }

        expect($failure?->needed)->toBe(2_001)
            ->and($failure?->available)->toBe(2_000)
            ->and($failure?->getMessage())->toBe('This needs 3 credits; 2 are available.')
            ->and(walletOf($tenant)?->reserved)->toBe(8_000)
            ->and(asWorkspace($tenant, fn (): int => CreditReservation::query()->count()))->toBe(1);
    });

    it('refuses any hold when the workspace has no wallet yet', function (): void {
        $tenant = Tenant::factory()->create();

        expect(fn (): mixed => asWorkspace($tenant, fn () => resolve(ReserveCredits::class)->handle('unit-1', 1)))
            ->toThrow(InsufficientCredits::class);
    });

    it('holds exactly what is available, and not one millicredit more', function (): void {
        $tenant = Tenant::factory()->create();
        reserveFor($tenant, 10_000, grant: 10_000);

        expect(walletOf($tenant)?->available())->toBe(0)
            ->and(fn (): mixed => asWorkspace($tenant, fn () => resolve(ReserveCredits::class)->handle('unit-2', 1)))->toThrow(InsufficientCredits::class);
    });

    it('allows one reservation per work unit reference, and a refused duplicate holds nothing', function (): void {
        $tenant = Tenant::factory()->create();
        reserveFor($tenant, 1_000, 'unit-7', grant: 10_000);

        expect(fn (): mixed => asWorkspace($tenant, fn () => DB::transaction(fn () => resolve(ReserveCredits::class)->handle('unit-7', 1_000))))
            ->toThrow(QueryException::class);
        expect(walletOf($tenant)?->reserved)->toBe(1_000);
    });

    it('cannot be overdrawn even by a raw update that skips the actions', function (string $sql): void {
        $tenant = Tenant::factory()->create();
        reserveFor($tenant, 1_000, grant: 1_000);

        expect(fn (): mixed => asWorkspace($tenant, fn () => DB::transaction(fn () => DB::update($sql, [$tenant->id]))))
            ->toThrow(QueryException::class, 'check');
    })->with([
        'hold more than the balance' => ['UPDATE credit_wallets SET reserved = balance + 1 WHERE tenant_id = ?'],
        'negative balance' => ['UPDATE credit_wallets SET balance = -1, reserved = 0 WHERE tenant_id = ?'],
        'consume past the reservation' => ['UPDATE credit_reservations SET consumed = amount + 1 WHERE tenant_id = ?'],
    ]);
});

describe('consumption and settlement', function (): void {
    it('charges attempts against the hold and never past it', function (): void {
        $tenant = Tenant::factory()->create();
        $reservation = reserveFor($tenant, 1_000, grant: 10_000);

        [$first, $second, $third] = asWorkspace($tenant, fn (): array => [
            resolve(ConsumeReservation::class)->handle($reservation->id, 600),
            resolve(ConsumeReservation::class)->handle($reservation->id, 600),
            resolve(ConsumeReservation::class)->handle($reservation->id, 50),
        ]);

        expect([$first->charged, $first->unbilled])->toBe([600, 0])
            ->and([$second->charged, $second->unbilled])->toBe([400, 200])
            ->and([$third->charged, $third->unbilled])->toBe([0, 50])
            ->and(reservationOf($tenant, $reservation->id)->consumed)->toBe(1_000);
    });

    it('settles: charges what was consumed, releases the rest, writes one usage transaction', function (): void {
        $tenant = Tenant::factory()->create();
        $reservation = reserveFor($tenant, 3_000, grant: 10_000);

        $settled = asWorkspace($tenant, function () use ($reservation): bool {
            resolve(ConsumeReservation::class)->handle($reservation->id, 1_234);

            return resolve(SettleReservation::class)->handle($reservation->id);
        });

        $wallet = walletOf($tenant);
        $usage = transactionsOf($tenant)[1];
        expect($settled)->toBeTrue()
            ->and($wallet?->balance)->toBe(8_766)
            ->and($wallet?->reserved)->toBe(0)
            ->and([$usage->kind, $usage->amount, $usage->balance_after, $usage->reservation_id, $usage->external_ref])
            ->toBe(['ai_usage', -1_234, 8_766, $reservation->id, "reservation:{$reservation->id}"])
            ->and(reservationOf($tenant, $reservation->id)->status)->toBe('settled');
    });

    it('settles once: a second settlement and any later charge change nothing', function (): void {
        $tenant = Tenant::factory()->create();
        $reservation = reserveFor($tenant, 3_000, grant: 10_000);

        [$again, $late] = asWorkspace($tenant, function () use ($reservation): array {
            resolve(ConsumeReservation::class)->handle($reservation->id, 500);
            resolve(SettleReservation::class)->handle($reservation->id);

            return [
                resolve(SettleReservation::class)->handle($reservation->id),
                resolve(ConsumeReservation::class)->handle($reservation->id, 700),
            ];
        });

        expect($again)->toBeFalse()
            ->and([$late->charged, $late->unbilled])->toBe([0, 700])
            ->and(walletOf($tenant)?->balance)->toBe(9_500)
            ->and(transactionsOf($tenant))->toHaveCount(2);
    });

    it('releases an unused reservation without a usage transaction', function (): void {
        $tenant = Tenant::factory()->create();
        $reservation = reserveFor($tenant, 3_000, grant: 10_000);

        asWorkspace($tenant, fn (): bool => resolve(SettleReservation::class)->handle($reservation->id));

        expect(walletOf($tenant)?->only(['balance', 'reserved']))->toBe(['balance' => 10_000, 'reserved' => 0])
            ->and(transactionsOf($tenant))->toHaveCount(1);
    });

    it("cannot charge or settle another workspace's reservation", function (): void {
        $victim = Tenant::factory()->create();
        $attacker = Tenant::factory()->create();
        $reservation = reserveFor($victim, 3_000, grant: 10_000);
        reserveFor($attacker, 1_000, grant: 1_000);

        [$charge, $settled] = asWorkspace($attacker, fn (): array => [
            resolve(ConsumeReservation::class)->handle($reservation->id, 3_000),
            resolve(SettleReservation::class)->handle($reservation->id),
        ]);

        expect([$charge->charged, $charge->unbilled])->toBe([0, 3_000])
            ->and($settled)->toBeFalse()
            ->and(reservationOf($victim, $reservation->id)->only(['consumed', 'status']))->toBe(['consumed' => 0, 'status' => 'open'])
            ->and(walletOf($attacker)?->balance)->toBe(1_000);
    });

    it('keeps the transaction log append-only', function (): void {
        $tenant = Tenant::factory()->create();
        reserveFor($tenant, 1_000, grant: 1_000);

        expect(fn (): mixed => asWorkspace($tenant, fn () => DB::transaction(fn () => DB::update('UPDATE credit_transactions SET amount = 1000000 WHERE tenant_id = ?', [$tenant->id]))))
            ->toThrow(QueryException::class, 'append-only');
    });

    it('still lets a whole workspace be deleted, ledger included', function (): void {
        $tenant = Tenant::factory()->create();
        $reservation = reserveFor($tenant, 1_000, grant: 5_000);
        asWorkspace($tenant, fn (): bool => resolve(ConsumeReservation::class)->handle($reservation->id, 10)->charged > 0
            && resolve(SettleReservation::class)->handle($reservation->id));

        $tenant->delete();

        expect(tenancy()->runAsSystem('test', fn (): int => CreditTransaction::query()->count() + CreditReservation::query()->count()))->toBe(0);
    });
});
