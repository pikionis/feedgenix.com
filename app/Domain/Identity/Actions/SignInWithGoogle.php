<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Exceptions\GoogleSignInRefused;
use App\Domain\Identity\Exceptions\InvalidInvite;
use App\Domain\Identity\RegistrationMode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Two\User as GoogleUser;

/**
 * Google sign-in: finds the account, links an existing one safely, or signs up a new one.
 *
 * Legacy defect D20: the old code linked Google to any account with the same email and marked it
 * verified. If someone had registered that address first with their own password (never verified),
 * that password kept working on an account the real owner now used. Here, when Google proves the
 * address of an unverified account, the unverified password is removed and every open session of
 * that account is ended before the link.
 */
final readonly class SignInWithGoogle
{
    public function __construct(
        private ProvisionWorkspace $provisionWorkspace,
        private RedeemInvite $redeemInvite,
    ) {}

    /** @throws GoogleSignInRefused */
    public function handle(GoogleUser $google, ?string $inviteCode): User
    {
        $email = $google->getEmail();
        $raw = $google->getRaw();
        $verifiedByGoogle = ($raw['email_verified'] ?? $raw['verified_email'] ?? false) === true;

        if (! is_string($email) || $email === '' || ! $verifiedByGoogle) {
            throw new GoogleSignInRefused('unverified_email');
        }

        return DB::transaction(function () use ($google, $email, $inviteCode): User {
            $linked = User::query()->where('google_id', (string) $google->getId())->lockForUpdate()->first();
            if ($linked instanceof User) {
                return $linked;
            }

            $existing = User::query()->where('email', $email)->lockForUpdate()->first();

            return $existing instanceof User
                ? $this->link($existing, $google)
                : $this->signUp($google, $email, $inviteCode);
        });
    }

    private function link(User $user, GoogleUser $google): User
    {
        if ($user->google_id !== null) {
            // The address belongs to an account already linked to a different Google identity.
            throw new GoogleSignInRefused('conflict');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->forceFill(['password' => null, 'remember_token' => Str::random(60)]);
            DB::table('sessions')->where('user_id', $user->id)->delete();
            $user->markEmailAsVerified();
        }

        $user->forceFill([
            'google_id' => (string) $google->getId(),
            'avatar_url' => $user->avatar_url ?? $google->getAvatar(),
        ])->save();

        if ($user->current_tenant_id === null) {
            $this->provisionWorkspace->handle($user);
        }

        return $user;
    }

    private function signUp(GoogleUser $google, string $email, ?string $inviteCode): User
    {
        $mode = RegistrationMode::current();
        if ($mode === RegistrationMode::Closed) {
            throw new GoogleSignInRefused('closed');
        }

        $invite = null;
        if ($mode === RegistrationMode::Invite) {
            try {
                $invite = $this->redeemInvite->handle($inviteCode, $email);
            } catch (InvalidInvite) {
                throw new GoogleSignInRefused('invite');
            }
        }

        $user = new User([
            'name' => $google->getName() ?: Str::before($email, '@'),
            'email' => $email,
        ]);
        $user->forceFill([
            'google_id' => (string) $google->getId(),
            'avatar_url' => $google->getAvatar(),
            'email_verified_at' => now(),
        ])->save();

        $this->provisionWorkspace->handle($user);

        $invite?->forceFill(['used_by' => $user->id])->save();

        return $user;
    }
}
