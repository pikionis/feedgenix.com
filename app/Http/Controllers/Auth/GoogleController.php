<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Domain\Identity\Actions\SignInWithGoogle;
use App\Domain\Identity\Exceptions\GoogleSignInRefused;
use App\Support\Frontend;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;
use Throwable;

/**
 * Google sign-in. These routes live in web.php: the session holds the OAuth state (CSRF protection)
 * and, during the private beta, the invite code the person arrived with.
 */
final class GoogleController
{
    public const string INVITE_SESSION_KEY = 'signup_invite';

    public function redirect(Request $request): SymfonyRedirect
    {
        $invite = $request->query('invite');
        if (is_string($invite) && $invite !== '') {
            $request->session()->put(self::INVITE_SESSION_KEY, mb_substr($invite, 0, 64));
        }

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, SignInWithGoogle $signIn): RedirectResponse
    {
        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable) {
            return redirect()->away(Frontend::url('/login', ['error' => 'google']));
        }

        if (! $google instanceof GoogleUser) {
            return redirect()->away(Frontend::url('/login', ['error' => 'google']));
        }

        $invite = $request->session()->pull(self::INVITE_SESSION_KEY);

        try {
            $user = $signIn->handle($google, is_string($invite) ? $invite : null);
        } catch (GoogleSignInRefused $refused) {
            $page = in_array($refused->reason, ['invite', 'closed'], true) ? '/register' : '/login';

            return redirect()->away(Frontend::url($page, ['error' => $refused->reason]));
        }

        Auth::guard('web')->login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->away(Frontend::url('/app'));
    }
}
