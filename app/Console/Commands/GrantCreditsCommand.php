<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Credits\Actions\GrantCredits;
use App\Domain\Credits\Millicredits;
use App\Domain\Credits\Models\CreditWallet;
use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use Brick\Math\BigDecimal;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Staff tool: add credits to a workspace. Until the wallet is sold (M6) this is how the owner funds
 * a beta workspace's AI (ADR 0016): the grant goes into the same wallet and reservation path that
 * purchases will use. Server access is the authorisation.
 */
#[AsCommand(name: 'credits:grant', description: 'Add credits to a workspace (staff grant, e.g. the private-beta AI budget)')]
#[Signature('credits:grant
        {tenant : Workspace id or slug}
        {euros : Credit value in euros, e.g. 10 or 2.50 (1 credit = €0.0001)}
        {--note= : Why, kept with the transaction}')]
final class GrantCreditsCommand extends Command
{
    private const string MAX_EUROS = '10000';

    public function handle(TenantContext $context, GrantCredits $grant): int
    {
        try {
            $tenant = $this->tenant();
            $millicredits = $this->millicredits();
        } catch (InvalidArgumentException $invalid) {
            $this->error($invalid->getMessage());

            return self::INVALID;
        }
        $note = $this->option('note');

        $context->run($tenant->id, static function () use ($grant, $millicredits, $note): void {
            $grant->handle($millicredits, 'grant', is_string($note) && $note !== '' ? mb_substr($note, 0, 255) : null);
        });
        $available = $context->run($tenant->id, static fn (): int => CreditWallet::query()->firstOrFail()->available());

        $this->info(sprintf('Granted %s credits to %s (#%d).', number_format(intdiv($millicredits, Millicredits::PER_CREDIT)), $tenant->slug, $tenant->id));
        $this->line(sprintf('Available now: %s credits.', number_format(intdiv($available, Millicredits::PER_CREDIT))));

        return self::SUCCESS;
    }

    private function tenant(): Tenant
    {
        $key = (string) $this->argument('tenant');
        $tenant = ctype_digit($key)
            ? Tenant::query()->find((int) $key)
            : Tenant::query()->where('slug', $key)->first();

        return $tenant ?? throw new InvalidArgumentException("No workspace [{$key}].");
    }

    private function millicredits(): int
    {
        $euros = (string) $this->argument('euros');
        $valid = preg_match('/^\d{1,5}(?:\.\d{1,4})?$/', $euros) === 1
            && BigDecimal::of($euros)->isPositive()
            && BigDecimal::of($euros)->isLessThanOrEqualTo(self::MAX_EUROS);
        if (! $valid) {
            throw new InvalidArgumentException('euros must be a positive amount up to '.self::MAX_EUROS.', with at most 4 decimals (e.g. 10 or 2.50).');
        }

        return Millicredits::fromEuros($euros);
    }
}
