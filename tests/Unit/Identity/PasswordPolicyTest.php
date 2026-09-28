<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Fake the Have I Been Pwned range API: $breached is "known", everything else is not. */
function fakeBreachedPasswords(string $breached): void
{
    $hash = strtoupper(sha1($breached));
    Http::fake([
        'api.pwnedpasswords.com/range/'.substr($hash, 0, 5) => Http::response(substr($hash, 5).":3861493\r\n"),
        'api.pwnedpasswords.com/*' => Http::response(''),
    ]);
}

function passes(string $password): bool
{
    return Validator::make(['password' => $password], ['password' => [Password::defaults()]])->passes();
}

it('rejects passwords from public breaches in production, without sending the password', function (): void {
    app()->detectEnvironment(fn (): string => 'production');
    fakeBreachedPasswords('password123');

    expect(passes('password123'))->toBeFalse()
        ->and(passes('a long unique sentence nobody used'))->toBeTrue();

    // Only the first five characters of the SHA-1 hash ever leave the server.
    Http::assertSent(fn ($request): bool => preg_match('#/range/[0-9A-F]{5}$#', $request->url()) === 1);
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'password123'));
});

it('never calls the breach API outside production', function (): void {
    Http::fake();

    expect(passes('password123'))->toBeTrue()->and(passes('short'))->toBeFalse();

    Http::assertNothingSent();
});
