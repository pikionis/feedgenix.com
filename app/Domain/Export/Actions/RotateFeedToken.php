<?php

declare(strict_types=1);

namespace App\Domain\Export\Actions;

use App\Domain\Export\FeedUrls;
use App\Domain\Export\Models\ExportProfile;
use App\Domain\Export\Models\FeedToken;
use Illuminate\Support\Facades\DB;

/**
 * A new secret for a profile's URLs (ADR 0007). The old URLs keep working for the grace period, so
 * channels can be switched over; then they answer 404. A token already rotated keeps its end.
 */
final class RotateFeedToken
{
    public function handle(ExportProfile $profile): string
    {
        return DB::transaction(static function () use ($profile): string {
            ExportProfile::query()->whereKey($profile->id)->lockForUpdate()->firstOrFail();
            FeedToken::query()->where('profile_id', $profile->id)->whereNull('expires_at')
                ->update(['expires_at' => now()->addHours((int) config('export.token_grace_hours', 72))]);

            return FeedUrls::issue($profile);
        });
    }
}
