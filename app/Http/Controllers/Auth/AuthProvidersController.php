<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;

/** Which sign-in methods the login and sign-up pages offer. Never reveals a credential. */
final class AuthProvidersController
{
    /**
     * Sign-in methods.
     *
     * `google` is true only when the server has Google OAuth credentials; email and password always work.
     */
    public function __invoke(): JsonResponse
    {
        return response()->json(['google' => GoogleController::configured()]);
    }
}
