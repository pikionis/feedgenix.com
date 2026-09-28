<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class SessionController
{
    /**
     * Sign in.
     *
     * One answer for every failure (unknown address, wrong password, Google-only account), and
     * Laravel's timebox keeps the response time the same, so the endpoint cannot tell which
     * addresses have accounts.
     */
    public function store(LoginRequest $request): UserResource
    {
        $credentials = [
            'email' => (string) $request->validated('email'),
            'password' => (string) $request->validated('password'),
        ];

        if (! Auth::guard('web')->attempt($credentials, $request->boolean('remember', true))) {
            throw ValidationException::withMessages(['email' => [trans('auth.failed')]]);
        }

        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return new UserResource($this->user($request));
    }

    /** The signed-in person. */
    public function show(Request $request): UserResource
    {
        return new UserResource($this->user($request));
    }

    /** Sign out and end the session. */
    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $request->user() ?? Auth::guard('web')->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
