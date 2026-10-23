<?php

declare(strict_types=1);

use App\Domain\Credits\Actions\ConsumeReservation;
use App\Models\Tenant;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

/** @return array{0: int, 1: string} exit code and output */
function creditsCommand(string $command, array $arguments = []): array
{
    $output = new BufferedOutput;
    $code = Artisan::call($command, $arguments, $output);

    return [$code, $output->fetch()];
}

describe('credits:grant', function (): void {
    it('adds the credit value of an amount in euros (1 credit = €0.0001)', function (): void {
        $tenant = Tenant::factory()->create();

        [$code, $output] = creditsCommand('credits:grant', ['tenant' => $tenant->slug, 'euros' => '10', '--note' => 'beta budget']);

        expect($code)->toBe(0)
            ->and(walletOf($tenant)?->balance)->toBe(100_000_000)
            ->and($output)->toContain('Granted 100,000 credits')->toContain('Available now: 100,000 credits');
    });

    it('takes cents exactly, without float rounding', function (): void {
        $tenant = Tenant::factory()->create();

        creditsCommand('credits:grant', ['tenant' => (string) $tenant->id, 'euros' => '0.29']);

        // 0.29 is 0.28999999999999998 as a float; exact decimals give exactly 2,900 credits.
        expect(walletOf($tenant)?->balance)->toBe(2_900_000);
    });

    it('refuses anything but a positive amount with at most 4 decimals, up to €10,000', function (string $euros): void {
        $tenant = Tenant::factory()->create();

        [$code] = creditsCommand('credits:grant', ['tenant' => $tenant->slug, 'euros' => $euros]);

        expect($code)->toBe(2)->and(walletOf($tenant))->toBeNull();
    })->with(['0', '0.0000', '-5', 'abc', '1.23456', '10000.01', '1e3', '', ' 10', '10,5']);

    it('refuses an unknown workspace', function (): void {
        [$code, $output] = creditsCommand('credits:grant', ['tenant' => 'nope', 'euros' => '10']);

        expect($code)->toBe(2)->and($output)->toContain('No workspace [nope]');
    });
});

describe('credits:settle-expired', function (): void {
    it('settles only reservations past their expiry, each as its own workspace', function (): void {
        $first = Tenant::factory()->create();
        $second = Tenant::factory()->create();
        $expiring = reserveFor($first, 2_000, 'old', grant: 10_000);
        tenancy()->run($first->id, fn () => resolve(ConsumeReservation::class)->handle($expiring->id, 300));
        $this->travel(61)->minutes();
        $fresh = reserveFor($second, 1_000, 'new', grant: 5_000);

        [$code, $output] = creditsCommand('credits:settle-expired');

        expect($code)->toBe(0)
            ->and($output)->toContain('Settled 1 expired reservation(s).')
            ->and(reservationOf($first, $expiring->id)->status)->toBe('settled')
            ->and(walletOf($first)?->only(['balance', 'reserved']))->toBe(['balance' => 9_700, 'reserved' => 0])
            ->and(reservationOf($second, $fresh->id)->status)->toBe('open')
            ->and(walletOf($second)?->reserved)->toBe(1_000);
    });
});
