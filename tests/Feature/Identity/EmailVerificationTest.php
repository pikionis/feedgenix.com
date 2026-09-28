<?php

declare(strict_types=1);

use App\Domain\Identity\EmailHash;
use App\Models\User;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;

function verificationLink(User $user, int $minutes = 60): string
{
    return URL::temporarySignedRoute('verification.verify', now()->addMinutes($minutes), [
        'id' => $user->id,
        'hash' => EmailHash::of($user->email),
    ]);
}

it('verifies with the signed link and returns to the app', function (): void {
    $user = User::factory()->unverified()->create();

    $this->get(verificationLink($user))->assertRedirect(config('feedgenix.frontend_url').'/app?verified=1');

    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();
});

it('verifies with the very link the email contains', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create();
    $user->sendEmailVerificationNotification();

    $link = null;
    Notification::assertSentTo($user, VerifyEmail::class, function (VerifyEmail $mail) use ($user, &$link): bool {
        $link = (string) $mail->toMail($user)->actionUrl;

        return str_contains($link, '/api/auth/verify-email/'.$user->id.'/'.EmailHash::of($user->email));
    });

    $this->get((string) $link)->assertRedirect();
    expect($user->fresh()?->hasVerifiedEmail())->toBeTrue();
});

it('refuses a signed link whose hash belongs to another address', function (): void {
    $user = User::factory()->unverified()->create();
    $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => EmailHash::of('someone@else.com')]);

    $this->get($link)->assertForbidden();

    expect($user->fresh()?->hasVerifiedEmail())->toBeFalse();
});

it('refuses a tampered, unsigned or expired link', function (): void {
    $user = User::factory()->unverified()->create();
    $other = User::factory()->unverified()->create();

    $this->get(str_replace("/{$user->id}/", "/{$other->id}/", verificationLink($user)))->assertForbidden();
    $this->get("/api/auth/verify-email/{$user->id}/".EmailHash::of($user->email))->assertForbidden();

    $expired = verificationLink($user, minutes: 1);
    $this->travel(2)->minutes();
    $this->get($expired)->assertForbidden();

    expect($user->fresh()?->hasVerifiedEmail())->toBeFalse()->and($other->fresh()?->hasVerifiedEmail())->toBeFalse();
});

it('sends the email again on request, but not to a verified address', function (): void {
    Notification::fake();
    $unverified = User::factory()->unverified()->create();
    $verified = User::factory()->create();

    $this->actingAs($unverified)->postJson('/api/v1/auth/email/verification-notification')->assertOk()->assertJson(['status' => 'sent']);
    $this->actingAs($verified)->postJson('/api/v1/auth/email/verification-notification')->assertOk()->assertJson(['status' => 'already_verified']);

    Notification::assertSentTo($unverified, VerifyEmail::class);
    Notification::assertNotSentTo($verified, VerifyEmail::class);
});

it('limits how often the email can be sent again', function (): void {
    Notification::fake();
    $user = User::factory()->unverified()->create();

    foreach (range(1, 6) as $attempt) {
        $this->actingAs($user)->postJson('/api/v1/auth/email/verification-notification')->assertOk();
    }

    $this->actingAs($user)->postJson('/api/v1/auth/email/verification-notification')->assertTooManyRequests();
});
