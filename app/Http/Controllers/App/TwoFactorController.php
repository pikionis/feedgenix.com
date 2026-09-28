<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Domain\Identity\TwoFactor\Exceptions\InvalidTwoFactorCode;
use App\Domain\Identity\TwoFactor\Exceptions\TwoFactorAlreadyEnabled;
use App\Domain\Identity\TwoFactor\TwoFactor;
use App\Domain\Identity\TwoFactor\TwoFactorSession;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Two-factor sign-in for staff: set up, confirm, prove it in a session, renew recovery codes.
 * (Customers get it later, together with enforcing it at sign-in.)
 */
final readonly class TwoFactorController
{
    public function __construct(private TwoFactor $twoFactor) {}

    /** The state of two-factor sign-in for this account and this session. Never the secret. */
    public function show(Request $request): JsonResponse
    {
        $user = $this->staff($request);

        $enabled = $user->two_factor_confirmed_at !== null;

        return response()->json([
            'enabled' => $enabled,
            'pending' => $enabled ? false : $user->two_factor_secret !== null,
            'recovery_codes_left' => count($user->two_factor_recovery_codes ?? []),
            'passed' => TwoFactorSession::passed($request),
        ]);
    }

    /**
     * Start setting it up: a new secret, as a QR code and as text for manual entry.
     *
     * @throws TwoFactorAlreadyEnabled
     */
    public function begin(Request $request): JsonResponse
    {
        return response()->json($this->twoFactor->begin($this->staff($request)));
    }

    /**
     * Finish setting it up with a first code. The recovery codes are shown only now.
     *
     * @response array{recovery_codes: list<string>}
     */
    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:20']]);
        $codes = $this->twoFactor->confirm($this->staff($request), (string) $data['code']);
        TwoFactorSession::markPassed($request);

        return response()->json(['recovery_codes' => $codes]);
    }

    /** Prove the second factor for this session: an app code or a recovery code. */
    public function challenge(Request $request): Response
    {
        $data = $request->validate(['code' => ['required', 'string', 'max:40']]);

        if (! $this->twoFactor->verify($this->staff($request), (string) $data['code'])) {
            throw new InvalidTwoFactorCode('That code did not match, or it was already used.');
        }

        TwoFactorSession::markPassed($request);

        return response()->noContent();
    }

    /**
     * New recovery codes (the old ones stop working). Needs the second factor in this session.
     *
     * @response array{recovery_codes: list<string>}
     */
    public function recoveryCodes(Request $request): JsonResponse
    {
        $user = $this->staff($request);
        abort_unless($user->two_factor_confirmed_at !== null && TwoFactorSession::passed($request), 403, 'Confirm it is you first.');

        return response()->json(['recovery_codes' => $this->twoFactor->regenerateRecoveryCodes($user)]);
    }

    private function staff(Request $request): User
    {
        // The proof lives in the browser session; token-authenticated calls cannot hold it.
        abort_unless($request->hasSession(), 400, 'Two-factor sign-in needs a browser session.');
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_staff, 403, 'Two-factor sign-in is available to staff for now.');

        return $user;
    }
}
