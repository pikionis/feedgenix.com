<?php

declare(strict_types=1);

namespace App\Domain\Feeds\Actions;

use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanLimitExceeded;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Feeds\Models\FeedSource;
use App\Domain\Ingest\Fetch\Credentials;
use App\Domain\Ingest\Fetch\Target;
use App\Domain\Ingest\Fetch\UrlGuard;
use App\Domain\Ingest\ParseOptions;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a feed within the plan's feed limit. Concurrent creations are serialised on the
 * workspace row, so two requests can never together pass the limit.
 */
final class CreateFeed
{
    /**
     * @throws PlanLimitExceeded
     * @throws ValidationException when the login may not be used on this address
     */
    public function handle(Tenant $workspace, string $name, ?string $url, ParseOptions $options, ?Credentials $login = null, ?string $hostKey = null): Feed
    {
        $target = $url === null ? null : UrlGuard::check($url);
        if ($target instanceof Target) {
            ChangeFeedSource::assertAllowed($target, $login, $hostKey);
        }

        return DB::transaction(static function () use ($workspace, $name, $target, $options, $login, $hostKey): Feed {
            $locked = Tenant::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            Entitlements::for($locked)->assertWithin('feeds');

            $feed = Feed::query()->create([
                'name' => $name,
                'source' => $target instanceof Target ? 'url' : 'upload',
                'parse_options' => $options->toArray(),
            ]);

            if ($target instanceof Target) {
                FeedSource::query()->create([
                    'feed_id' => $feed->id,
                    'url' => $target->url,
                    'host' => $target->host,
                    'auth_type' => $login?->type,
                    'credentials' => $login?->toArray(),
                    'host_key' => $hostKey,
                ]);
            }

            return $feed;
        });
    }
}
