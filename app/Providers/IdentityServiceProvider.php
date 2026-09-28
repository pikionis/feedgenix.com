<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Identity\EmailHash;
use App\Models\User;
use App\Support\Frontend;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

/** Sign-in rules: password policy, rate limits (legacy defect D18) and links into the SPA. */
final class IdentityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Production also rejects passwords found in public breaches (a k-anonymity range lookup:
        // the password never leaves the server). Tests never reach that API.
        Password::defaults(fn (): Password => $this->app->isProduction()
            ? Password::min(8)->max(200)->uncompromised()
            : Password::min(8)->max(200));

        // The verification link is signed (HMAC); its address hash is SHA-256, not Laravel's SHA-1.
        VerifyEmail::createUrlUsing(static fn (User $user): string => URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes((int) config('auth.verification.expire', 60)),
            ['id' => $user->getKey(), 'hash' => EmailHash::of($user->getEmailForVerification())],
        ));

        // The reset email links into the SPA, which posts the token back to the API.
        ResetPassword::createUrlUsing(static fn (User $user, string $token): string => Frontend::url('/reset-password', [
            'token' => $token,
            'email' => $user->getEmailForPasswordReset(),
        ]));

        RateLimiter::for('login', static fn (Request $request): array => [
            Limit::perMinute(5)->by('login:'.self::emailKey($request).'|'.$request->ip()),
            Limit::perMinute(30)->by('login-ip:'.$request->ip()),
        ]);

        RateLimiter::for('register', static fn (Request $request): array => [
            Limit::perMinute(5)->by('register:'.$request->ip()),
            Limit::perHour(20)->by('register-hour:'.$request->ip()),
        ]);

        RateLimiter::for('password-reset', static fn (Request $request): array => [
            Limit::perMinute(3)->by('reset:'.self::emailKey($request).'|'.$request->ip()),
            Limit::perHour(20)->by('reset-hour:'.$request->ip()),
        ]);

        RateLimiter::for('oauth', static fn (Request $request): Limit => Limit::perMinute(20)->by('oauth:'.$request->ip()));
    }

    private static function emailKey(Request $request): string
    {
        $email = $request->input('email');

        return is_string($email) ? Str::lower(trim($email)) : '';
    }
}
