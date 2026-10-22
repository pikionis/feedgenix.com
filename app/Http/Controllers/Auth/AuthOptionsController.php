<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\RegistrationMode;
use Illuminate\Http\JsonResponse;

/** What the sign-in and sign-up pages offer. Never reveals a credential. */
final class AuthOptionsController
{
    /**
     * Sign-in and sign-up options.
     *
     * `google` is true only when the server has Google OAuth credentials; email and password always
     * work. `registration` is who may sign up: `open` (anyone), `invite` (with an invite code, during
     * the private beta) or `closed`.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'google' => GoogleController::configured(),
            // The enum itself (serialised as its value), so the API contract lists the three modes.
            'registration' => RegistrationMode::current(),
        ]);
    }
}
