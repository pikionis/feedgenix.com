<?php

declare(strict_types=1);

namespace App\Domain\Identity\TwoFactor;

use Illuminate\Http\Request;

/** A staff session proves the second factor once, then it holds for a working day. */
final class TwoFactorSession
{
    public const string KEY = 'two_factor_passed_at';

    public const int LIFETIME_HOURS = 12;

    public static function markPassed(Request $request): void
    {
        // A new session id: a session fixed before the check never inherits the proof.
        $request->session()->regenerate();
        $request->session()->put(self::KEY, now()->getTimestamp());
    }

    public static function passed(Request $request): bool
    {
        if (! $request->hasSession()) {
            return false;
        }

        $at = $request->session()->get(self::KEY);

        return is_int($at) && $at > now()->subHours(self::LIFETIME_HOURS)->getTimestamp();
    }
}
