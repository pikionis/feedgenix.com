<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\PasswordResetController;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

beforeEach(fn () => Notification::fake());

it('answers identically whether or not the address has an account (legacy D19)', function (): void {
    $user = User::factory()->create(['email' => 'known@example.com']);

    $known = $this->postJson('/api/auth/forgot-password', ['email' => 'known@example.com'])->assertOk();
    $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'unknown@example.com'])->assertOk();

    expect($known->getContent())->toBe($unknown->getContent())
        ->and($known->json('status'))->toBe(PasswordResetController::LINK_SENT);
    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertCount(1);
});

it('links into the app, which posts the token back', function (): void {
    $user = User::factory()->create(['email' => 'known@example.com']);

    $this->postJson('/api/auth/forgot-password', ['email' => 'known@example.com']);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $url = (string) $notification->toMail($user)->actionUrl;

        return str_starts_with($url, config('feedgenix.frontend_url').'/reset-password?token=')
            && str_contains($url, 'email=known%40example.com');
    });
});

it('resets the password and signs the account out everywhere', function (): void {
    $user = User::factory()->create(['remember_token' => 'old-remember-token']);
    DB::table('sessions')->insert([
        'id' => 'other-device', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time(),
    ]);
    $token = Password::broker()->createToken($user);

    $this->postJson('/api/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a brand new password',
        'password_confirmation' => 'a brand new password',
    ])->assertOk();

    $fresh = $user->fresh();
    expect(Hash::check('a brand new password', (string) $fresh?->getAuthPassword()))->toBeTrue()
        ->and($fresh?->getRememberToken())->not->toBe('old-remember-token')
        ->and(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0)
        ->and(Auth::guard('web')->validate(['email' => $user->email, 'password' => 'password']))->toBeFalse();
});

it('refuses an invalid token and leaves the password alone', function (): void {
    $user = User::factory()->create();

    $this->postJson('/api/auth/reset-password', [
        'token' => 'not-a-real-token',
        'email' => $user->email,
        'password' => 'a brand new password',
        'password_confirmation' => 'a brand new password',
    ])->assertUnprocessable()->assertJsonValidationErrors('email');

    expect(Hash::check('password', (string) $user->fresh()?->getAuthPassword()))->toBeTrue();
});

it('is rate limited', function (): void {
    foreach (range(1, 3) as $attempt) {
        $this->postJson('/api/auth/forgot-password', ['email' => 'known@example.com'])->assertOk();
    }

    $this->postJson('/api/auth/forgot-password', ['email' => 'known@example.com'])->assertTooManyRequests();
});
