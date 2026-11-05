<?php

declare(strict_types=1);

namespace App\Domain\Feeds\Actions;

use App\Domain\Feeds\Models\Feed;
use App\Domain\Feeds\Models\FeedSource;
use App\Domain\Ingest\Fetch\Credentials;
use App\Domain\Ingest\Fetch\Target;
use App\Domain\Ingest\Fetch\UrlGuard;
use Illuminate\Validation\ValidationException;

/**
 * Re-points a URL feed or changes its login (ADR 0030). What is not sent is kept (legacy D16), with
 * one exception: a stored login never follows the address to another origin, or anyone who may edit
 * the feed could have it delivered to their own server.
 *
 * A new address or a new login also forgets the ETag and Last-Modified, which described what the
 * old ones were served.
 */
final class ChangeFeedSource
{
    /**
     * @param  Target|null  $target  the new address; null keeps the stored one
     * @param  bool  $changeLogin  whether the login was sent at all; $login null then removes it
     *
     * @throws ValidationException
     */
    public function handle(Feed $feed, ?Target $target, bool $changeLogin, ?Credentials $login): void
    {
        if ($feed->source !== 'url') {
            throw ValidationException::withMessages([$target instanceof Target ? 'url' : 'auth' => 'This feed is fed by uploads; create a URL feed instead.']);
        }

        $source = FeedSource::query()->where('feed_id', $feed->id)->firstOrFail();
        $current = UrlGuard::check($source->url);
        $next = $target ?? $current;

        $refusal = $changeLogin ? $login?->refusalFor($next) : null;
        if ($refusal !== null) {
            throw ValidationException::withMessages(['auth' => $refusal]);
        }

        $forget = ['etag' => null, 'last_modified' => null];
        if ($target instanceof Target) {
            $source->fill(['url' => $target->url, 'host' => $target->host, ...$forget]);
        }
        if ($changeLogin || $next->origin() !== $current->origin()) {
            $source->fill(['auth_type' => $login?->type, 'credentials' => $login?->toArray(), ...$forget]);
        }

        $source->save();
    }
}
