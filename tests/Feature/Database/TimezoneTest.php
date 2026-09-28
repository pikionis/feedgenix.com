<?php

declare(strict_types=1);

use App\Domain\Identity\Models\SignupInvite;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

// Found in M1: the local PostgreSQL ran in Europe/Bucharest while the app writes UTC timestamps
// without an offset, so every timestamptz value was stored three hours off.

it('runs every database session in UTC, whatever the server default is', function (): void {
    expect(DB::selectOne('show timezone')->TimeZone)->toBe('UTC');
});

it('reads back exactly the instant it wrote', function (): void {
    $expires = CarbonImmutable::parse('2026-01-15 08:30:00', 'UTC');

    $invite = new SignupInvite;
    $invite->forceFill(['code_hash' => str_repeat('a', 64), 'expires_at' => $expires])->save();

    expect(SignupInvite::query()->findOrFail($invite->id)->expires_at->equalTo($expires))->toBeTrue()
        ->and(DB::selectOne("select expires_at = timestamptz '2026-01-15 08:30:00+00' as same from signup_invites")->same)->toBeTrue();
});
