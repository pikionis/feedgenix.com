<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\CreateInvite;
use App\Domain\Identity\Models\SignupInvite;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function signUpPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Alice Martin',
        'email' => 'alice@example.com',
        'password' => 'correct horse battery',
        'password_confirmation' => 'correct horse battery',
    ], $overrides);
}

function invite(?string $email = null, int $days = 14): string
{
    return resolve(CreateInvite::class)->handle(email: $email, days: $days);
}

beforeEach(function (): void {
    config(['feedgenix.registration' => 'open']);
    Notification::fake();
});

it('signs up: an account, its own Free workspace, signed in and a verification email', function (): void {
    $response = $this->postJson('/api/auth/register', signUpPayload());

    $response->assertCreated()
        ->assertJsonPath('user.email', 'alice@example.com')
        ->assertJsonPath('user.email_verified', false)
        ->assertJsonPath('user.has_password', true)
        ->assertJsonPath('user.is_staff', false)
        ->assertJsonPath('user.workspace.role', 'owner')
        ->assertJsonPath('user.workspace.plan', 'free');

    $user = User::query()->where('email', 'alice@example.com')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    Notification::assertSentTo($user, VerifyEmail::class);
});

it('never returns the password hash or the remember token', function (): void {
    $response = $this->postJson('/api/auth/register', signUpPayload())->assertCreated();

    expect(array_keys($response->json('user')))->not->toContain('password')->not->toContain('remember_token')
        ->and((string) $response->getContent())->not->toContain('$2y$');
});

it('treats email addresses case-insensitively', function (): void {
    User::factory()->create(['email' => 'alice@example.com']);

    $this->postJson('/api/auth/register', signUpPayload(['email' => 'ALICE@Example.com']))
        ->assertUnprocessable()->assertJsonValidationErrors('email');
});

it('rejects weak or unconfirmed passwords', function (array $password): void {
    $this->postJson('/api/auth/register', signUpPayload($password))
        ->assertUnprocessable()->assertJsonValidationErrors('password');
})->with([
    'too short' => [['password' => 'short', 'password_confirmation' => 'short']],
    'not confirmed' => [['password_confirmation' => 'something else entirely']],
    'too long' => [['password' => str_repeat('x', 201), 'password_confirmation' => str_repeat('x', 201)]],
]);

it('ignores fields a person may not set: no staff access, no plan, no workspace, no verification', function (): void {
    $this->postJson('/api/auth/register', signUpPayload([
        'is_staff' => true,
        'plan' => 'custom',
        'current_tenant_id' => 999,
        'email_verified_at' => now()->toIso8601String(),
    ]))->assertCreated();

    $user = User::query()->where('email', 'alice@example.com')->firstOrFail();
    expect($user->is_staff)->toBeFalse()
        ->and($user->hasVerifiedEmail())->toBeFalse()
        ->and(Tenant::query()->findOrFail($user->current_tenant_id)->plan)->toBe('free');
});

it('needs a valid invite in the private beta, with one answer for every bad code', function (?string $code): void {
    config(['feedgenix.registration' => 'invite']);

    $this->postJson('/api/auth/register', signUpPayload(['invite_code' => $code]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['invite_code' => 'This invite code is not valid.']);

    expect(User::query()->count())->toBe(0);
})->with([
    'missing' => [null],
    'empty' => [''],
    'unknown' => ['fg-doesnotexist000000000000'],
]);

it('accepts an invite once and records who used it', function (): void {
    config(['feedgenix.registration' => 'invite']);
    $code = invite();

    $this->postJson('/api/auth/register', signUpPayload(['invite_code' => strtoupper($code)]))->assertCreated();
    $this->postJson('/api/auth/register', signUpPayload(['email' => 'bob@example.com', 'invite_code' => $code]))
        ->assertUnprocessable()->assertJsonValidationErrors('invite_code');

    $invite = SignupInvite::query()->sole();
    expect($invite->used_at)->not->toBeNull()
        ->and($invite->used_by)->toBe(User::query()->where('email', 'alice@example.com')->value('id'));
});

it('refuses an expired invite and an invite issued for another address', function (): void {
    config(['feedgenix.registration' => 'invite']);
    $expired = invite(days: 1);
    $forBob = invite(email: 'bob@example.com');

    $this->travel(2)->days();

    $this->postJson('/api/auth/register', signUpPayload(['invite_code' => $expired]))->assertJsonValidationErrors('invite_code');
    $this->postJson('/api/auth/register', signUpPayload(['invite_code' => $forBob]))->assertJsonValidationErrors('invite_code');
    $this->postJson('/api/auth/register', signUpPayload(['email' => 'BOB@example.com', 'invite_code' => $forBob]))->assertCreated();
});

it('refuses every sign-up while registration is closed', function (): void {
    config(['feedgenix.registration' => 'closed']);

    $this->postJson('/api/auth/register', signUpPayload())->assertForbidden();

    expect(User::query()->count())->toBe(0);
});

it('rolls everything back, invite included, when the workspace cannot be created', function (): void {
    config(['feedgenix.registration' => 'invite']);
    $code = invite();
    Tenant::creating(fn () => throw new RuntimeException('workspace store is down'));

    $this->postJson('/api/auth/register', signUpPayload(['invite_code' => $code]))->assertServerError();

    expect(User::query()->count())->toBe(0)
        ->and(SignupInvite::query()->sole()->used_at)->toBeNull();
    Notification::assertNothingSent();
});

it('is rate limited per address and network', function (): void {
    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/auth/register', signUpPayload(['password_confirmation' => 'nope']))->assertUnprocessable();
    }

    $this->postJson('/api/auth/register', signUpPayload())->assertTooManyRequests();
});
