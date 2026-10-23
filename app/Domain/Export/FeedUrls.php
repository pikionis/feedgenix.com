<?php

declare(strict_types=1);

namespace App\Domain\Export;

use App\Domain\Export\Dialects\Dialects;
use App\Domain\Export\Models\ExportProfile;
use App\Domain\Export\Models\FeedToken;
use Illuminate\Support\Str;

/**
 * The public URLs of a profile (ADR 0007): `/f/{token}/{file}`, and the same with `.gz`. The token
 * is 40 random characters; only its hash is looked up.
 */
final class FeedUrls
{
    /** @return array<string, string|null> file name => its language (null: every language, in columns) */
    public static function files(ExportProfile $profile): array
    {
        $extension = Dialects::for($profile->dialect)->extension();
        if ($profile->layout === 'wide') {
            return ["products.{$extension}" => null];
        }
        $files = [];
        foreach ($profile->locales as $locale) {
            $files["products-{$locale}.{$extension}"] = $locale;
        }

        return $files;
    }

    /** A new token for a profile; returns the token itself (shown in its URLs). */
    public static function issue(ExportProfile $profile): string
    {
        $token = Str::random(40);
        FeedToken::query()->forceCreate([
            'profile_id' => $profile->id,
            'token_hash' => FeedToken::hashOf($token),
            'token_encrypted' => $token,
        ]);

        return $token;
    }

    /** The profile's current token (the one without an end), or null. */
    public static function current(ExportProfile $profile): ?string
    {
        $token = FeedToken::query()->where('profile_id', $profile->id)->whereNull('expires_at')->latest('id')->first();

        return $token instanceof FeedToken ? $token->token_encrypted : null;
    }

    public static function url(string $token, string $file): string
    {
        return url("/f/{$token}/{$file}");
    }
}
