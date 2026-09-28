<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Exceptions\InvalidInvite;
use App\Domain\Identity\Models\SignupInvite;

/**
 * Claims an invite code for an email address. Call it inside the sign-up transaction: the row is
 * locked, so one code can never create two accounts. Every failure looks the same to the caller,
 * so a probe cannot tell an unknown code from a used or expired one.
 */
final class RedeemInvite
{
    /** @throws InvalidInvite */
    public function handle(?string $code, string $email): SignupInvite
    {
        if ($code === null || trim($code) === '') {
            throw new InvalidInvite;
        }

        $invite = SignupInvite::query()
            ->where('code_hash', SignupInvite::hash($code))
            ->lockForUpdate()
            ->first();

        $usable = $invite instanceof SignupInvite
            && $invite->used_at === null
            && $invite->expires_at->isFuture()
            && ($invite->email === null || mb_strtolower($invite->email) === mb_strtolower($email));

        if (! $usable) {
            throw new InvalidInvite;
        }

        $invite->forceFill(['used_at' => now()])->save();

        return $invite;
    }
}
