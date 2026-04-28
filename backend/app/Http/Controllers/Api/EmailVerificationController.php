<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * SPA-friendly email verification flow:
 *
 *   POST /api/email/verification-notification    (auth + throttled 6/min)
 *     Resend the signed verification link to the authenticated user.
 *
 *   GET /api/email/verify/{id}/{hash}            (signed; no auth needed)
 *     Click handler. The user opens the email in their inbox where they
 *     are typically NOT logged into our SPA — so the signature itself is
 *     the auth. We resolve the user from the URL id, verify the email-hash,
 *     mark verified, then redirect to the frontend.
 *
 *   GET /api/email/status                         (auth)
 *     Lightweight check used by the frontend banner.
 */
class EmailVerificationController extends Controller
{
    /**
     * Re-issue the verification email for the authenticated user.
     * Returns 200 even if already verified to avoid leaking timing info.
     */
    public function send(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        if ($user->hasVerifiedEmail()) {
            return response()->json(['message' => 'Already verified.'], 200);
        }
        $user->sendEmailVerificationNotification();
        return response()->json(['message' => 'Verification email sent.'], 200);
    }

    /**
     * Consume a signed verification link. The route is wrapped by the
     * `signed` middleware, which already validates the URL signature +
     * expiry. We additionally verify the email-hash matches the user
     * (defense in depth — prevents a leaked URL for one user from being
     * mutated to verify another) and then mark verified.
     *
     * Browser click → redirect to frontend success page.
     * API client     → JSON {verified: true}.
     */
    public function verify(Request $request, int $id, string $hash): JsonResponse|RedirectResponse
    {
        $user = User::find($id);
        if (!$user) {
            return $this->fail($request, 'unknown-user');
        }

        // Even if `signed` middleware blesses the URL, the hash binds the
        // link to this user's CURRENT email. If they changed email since
        // we issued the link, the old link must stop working.
        if (!hash_equals(sha1((string) $user->getEmailForVerification()), $hash)) {
            return $this->fail($request, 'invalid-hash');
        }

        if (!$user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        if ($request->wantsJson()) {
            return response()->json(['verified' => true, 'email' => $user->email]);
        }

        return redirect()->away(
            $this->frontendUrl() . '/email/verified',
        );
    }

    /**
     * Status endpoint for the frontend banner. Always returns 200 — the
     * `verified` boolean is what the UI keys on.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }
        return response()->json([
            'verified' => $user->hasVerifiedEmail(),
            'email' => $user->email,
        ]);
    }

    private function fail(Request $request, string $reason): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            return response()->json(['verified' => false, 'reason' => $reason], 403);
        }
        return redirect()->away(
            $this->frontendUrl() . '/email/verify-failed?reason=' . urlencode($reason),
        );
    }

    private function frontendUrl(): string
    {
        return rtrim((string) (config('app.frontend_url') ?: 'http://localhost:5173'), '/');
    }
}
