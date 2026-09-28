<?php

declare(strict_types=1);

use App\Domain\Identity\TwoFactor\TwoFactorSession;
use App\Domain\Tenancy\Models\TenantLanguage;
use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

/*
 * Feature tests boot the application on PostgreSQL (migrated once per process, each test in a
 * transaction). Unit tests boot it without touching the database. Arch tests only read the source
 * tree. The base TestCase blocks every real HTTP call.
 */
pest()->extend(TestCase::class)->use(LazilyRefreshDatabase::class)->in('Feature');
pest()->extend(TestCase::class)->in('Unit');

function tenancy(): TenantContext
{
    return resolve(TenantContext::class);
}

/** Give a workspace a target language (written as that workspace). */
function addLanguage(Tenant $tenant, string $code): TenantLanguage
{
    return tenancy()->run($tenant->id, fn (): TenantLanguage => TenantLanguage::query()->create(['language_code' => $code]));
}

/** A staff member with two-factor sign-in already set up. */
function staffWithTwoFactor(): User
{
    $staff = User::factory()->staff()->create();
    $staff->forceFill([
        'two_factor_secret' => (new Google2FA)->generateSecretKey(32),
        'two_factor_confirmed_at' => now(),
        'two_factor_recovery_codes' => [],
    ])->save();

    return $staff;
}

/** The session a staff member has after passing the two-factor check. */
function twoFactorPassedSession(): array
{
    return [TwoFactorSession::KEY => now()->getTimestamp()];
}

function currentTotp(User $user): string
{
    return (new Google2FA)->getCurrentOtp((string) $user->fresh()?->two_factor_secret);
}
