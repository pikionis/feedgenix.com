<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PasswordResetController
{
    public const string LINK_SENT = 'If an account exists for this address, we have emailed a link to reset the password.';

    /**
     * Email a password-reset link.
     *
     * The answer is identical whether or not the address has an account, and whether or not the
     * request was throttled (legacy defect D19: the old answer revealed which addresses exist).
     */
    public function sendLink(ForgotPasswordRequest $request): JsonResponse
    {
        Password::broker()->sendResetLink(['email' => (string) $request->validated('email')]);

        return response()->json(['status' => self::LINK_SENT]);
    }

    /**
     * Reset the password with the emailed token.
     *
     * Signs the account out everywhere: every session and "remember me" cookie stops working.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $status = Password::broker()->reset(
            [
                'email' => (string) $request->validated('email'),
                'token' => (string) $request->validated('token'),
                'password' => (string) $request->validated('password'),
                'password_confirmation' => (string) $request->input('password_confirmation'),
            ],
            static function (User $user, string $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => ['This password reset link is invalid or has expired.']]);
        }

        return response()->json(['status' => 'Your password has been reset. You can sign in now.']);
    }
}
