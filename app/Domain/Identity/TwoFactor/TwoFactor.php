<?php

declare(strict_types=1);

namespace App\Domain\Identity\TwoFactor;

use App\Domain\Identity\TwoFactor\Exceptions\InvalidTwoFactorCode;
use App\Domain\Identity\TwoFactor\Exceptions\TwoFactorAlreadyEnabled;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Time-based one-time passwords (RFC 6238), required for staff.
 *
 * - The secret is encrypted at rest; the QR code is drawn here, so the secret never goes to a
 *   third-party QR service.
 * - A code is accepted once per 30-second step (the last step is stored under a row lock, so two
 *   concurrent requests cannot both use it).
 * - Recovery codes are shown once and stored only as SHA-256 hashes; each works once.
 */
final readonly class TwoFactor
{
    /** Accept the previous and next 30-second step too (clock drift). */
    public const int WINDOW = 1;

    public const int RECOVERY_CODES = 8;

    public function __construct(private Google2FA $google2fa) {}

    /**
     * Start enrolment (or restart it while unconfirmed) with a new secret.
     *
     * @return array{secret: string, otpauth_url: string, qr_svg_data_url: string}
     *
     * @throws TwoFactorAlreadyEnabled
     */
    public function begin(User $user): array
    {
        if ($user->two_factor_confirmed_at !== null) {
            throw new TwoFactorAlreadyEnabled;
        }

        $secret = $this->google2fa->generateSecretKey(32);
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => null,
            'two_factor_last_step' => null,
        ])->save();

        $url = $this->google2fa->getQRCodeUrl('FeedGenix', $user->email, $secret);

        return ['secret' => $secret, 'otpauth_url' => $url, 'qr_svg_data_url' => $this->qrDataUrl($url)];
    }

    /**
     * Finish enrolment with a first code from the app.
     *
     * @return list<string> the recovery codes (shown once)
     *
     * @throws InvalidTwoFactorCode
     */
    public function confirm(User $user, string $code): array
    {
        return DB::transaction(function () use ($user, $code): array {
            $locked = $this->lock($user);
            if ($locked->two_factor_secret === null || $locked->two_factor_confirmed_at !== null || ! $this->acceptCode($locked, $code)) {
                throw new InvalidTwoFactorCode;
            }

            $codes = $this->newRecoveryCodes();
            $locked->forceFill([
                'two_factor_confirmed_at' => now(),
                'two_factor_recovery_codes' => array_map($this->hash(...), $codes),
            ])->save();
            $user->setRawAttributes($locked->getAttributes(), true);

            return $codes;
        });
    }

    /** A code from the app or an unused recovery code. */
    public function verify(User $user, string $input): bool
    {
        return DB::transaction(function () use ($user, $input): bool {
            $locked = $this->lock($user);
            if ($locked->two_factor_confirmed_at === null || $locked->two_factor_secret === null) {
                return false;
            }

            $normalised = self::normalise($input);
            $accepted = preg_match('/^\d{6}$/', $normalised) === 1
                ? $this->acceptCode($locked, $normalised)
                : $this->useRecoveryCode($locked, $normalised);

            $user->setRawAttributes($locked->getAttributes(), true);

            return $accepted;
        });
    }

    /** @return list<string> new recovery codes (shown once); the old ones stop working */
    public function regenerateRecoveryCodes(User $user): array
    {
        $codes = $this->newRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => array_map($this->hash(...), $codes)])->save();

        return $codes;
    }

    public static function normalise(string $input): string
    {
        return mb_strtolower(str_replace([' ', '-'], '', trim($input)));
    }

    private function acceptCode(User $user, string $code): bool
    {
        // With a previous step (0 = none yet) the library returns the matched step, or false.
        $step = $this->google2fa->verifyKeyNewer((string) $user->two_factor_secret, $code, $user->two_factor_last_step ?? 0, self::WINDOW);
        if (! is_int($step)) {
            return false;
        }

        $user->forceFill(['two_factor_last_step' => $step])->save();

        return true;
    }

    private function useRecoveryCode(User $user, string $code): bool
    {
        $hashes = $user->two_factor_recovery_codes ?? [];
        $given = $this->hash($code);

        foreach ($hashes as $index => $hash) {
            if (hash_equals($hash, $given)) {
                unset($hashes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();

                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function newRecoveryCodes(): array
    {
        return array_map(
            static fn (): string => Str::lower(Str::random(5)).'-'.Str::lower(Str::random(5)),
            range(1, self::RECOVERY_CODES),
        );
    }

    private function lock(User $user): User
    {
        return User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
    }

    private function qrDataUrl(string $url): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd)))->writeString($url);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    private function hash(string $code): string
    {
        return hash('sha256', self::normalise($code));
    }
}
