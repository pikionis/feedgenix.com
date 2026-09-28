<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Actions\RegisterUser;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class RegisterController
{
    /**
     * Sign up with email.
     *
     * Creates the account and its own workspace, signs the person in (unverified) and emails the
     * verification link. In the private beta an invite code is required.
     */
    public function __invoke(RegisterRequest $request, RegisterUser $register): JsonResponse
    {
        /** @var array{name: string, email: string, password: string} $data */
        $data = $request->safe()->only(['name', 'email', 'password']);
        $invite = $request->validated('invite_code');

        $user = $register->handle($data, is_string($invite) ? $invite : null);

        Auth::guard('web')->login($user, $request->boolean('remember', true));
        if ($request->hasSession()) {
            $request->session()->regenerate();
        }

        return (new UserResource($user))->response()->setStatusCode(201);
    }
}
