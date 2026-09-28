<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\EmailHash;
use App\Models\User;
use App\Support\Frontend;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class EmailVerificationController
{
    /**
     * Verify an email address.
     *
     * The link from the email: its signature and the address hash prove it, so no sign-in is needed.
     * Redirects into the app.
     */
    public function verify(string $id, string $hash): RedirectResponse
    {
        $user = User::query()->find((int) $id);

        if (! $user instanceof User || ! hash_equals(EmailHash::of($user->getEmailForVerification()), $hash)) {
            abort(403, 'This verification link is not valid.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return redirect()->away(Frontend::url('/app', ['verified' => 1]));
    }

    /** Send the verification email again. */
    public function resend(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        if ($user->hasVerifiedEmail()) {
            return response()->json(['status' => 'already_verified']);
        }

        $user->sendEmailVerificationNotification();

        return response()->json(['status' => 'sent']);
    }
}
