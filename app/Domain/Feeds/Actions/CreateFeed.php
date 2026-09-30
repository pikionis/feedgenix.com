<?php

declare(strict_types=1);

namespace App\Domain\Feeds\Actions;

use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanLimitExceeded;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Feeds\Models\FeedSource;
use App\Domain\Ingest\Fetch\Target;
use App\Domain\Ingest\Fetch\UrlGuard;
use App\Domain\Ingest\ParseOptions;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Creates a feed within the plan's feed limit. Concurrent creations are serialised on the
 * workspace row, so two requests can never together pass the limit.
 */
final class CreateFeed
{
    /** @throws PlanLimitExceeded */
    public function handle(Tenant $workspace, string $name, ?string $url, ParseOptions $options): Feed
    {
        $target = $url === null ? null : UrlGuard::check($url);

        return DB::transaction(static function () use ($workspace, $name, $target, $options): Feed {
            $locked = Tenant::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            Entitlements::for($locked)->assertWithin('feeds');

            $feed = Feed::query()->create([
                'name' => $name,
                'source' => $target instanceof Target ? 'url' : 'upload',
                'parse_options' => $options->toArray(),
            ]);

            if ($target instanceof Target) {
                FeedSource::query()->create(['feed_id' => $feed->id, 'url' => $target->url, 'host' => $target->host]);
            }

            return $feed;
        });
    }
}
