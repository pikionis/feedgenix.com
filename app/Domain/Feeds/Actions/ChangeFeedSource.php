<?php

declare(strict_types=1);

namespace App\Domain\Feeds\Actions;

use App\Domain\Feeds\Data\SourceChange;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Feeds\Models\FeedSource;
use App\Domain\Ingest\Fetch\Credentials;
use App\Domain\Ingest\Fetch\Target;
use App\Domain\Ingest\Fetch\UrlGuard;
use Illuminate\Validation\ValidationException;

/**
 * Re-points a URL feed or changes its login or trusted server key (ADR 0030, ADR 0032). What is not
 * sent is kept (legacy D16), with one exception: a stored login and a trusted key never follow the
 * address to another origin, or anyone who may edit the feed could have the login delivered to their
 * own server.
 *
 * A new address or a new login also forgets the ETag and Last-Modified, which described what the
 * old ones were served.
 */
final class ChangeFeedSource
{
    /** @throws ValidationException */
    public function handle(Feed $feed, SourceChange $change): void
    {
        if ($feed->source !== 'url') {
            throw ValidationException::withMessages([$change->target instanceof Target ? 'url' : 'auth' => 'This feed is fed by uploads; create a URL feed instead.']);
        }

        $source = FeedSource::query()->where('feed_id', $feed->id)->firstOrFail();
        $current = UrlGuard::check($source->url);
        $next = $change->target ?? $current;
        $moved = $next->origin() !== $current->origin();

        self::assertAllowed($next, $change->changeLogin ? $change->login : null, $change->changeHostKey ? $change->hostKey : null);

        $forget = ['etag' => null, 'last_modified' => null];
        if ($change->target instanceof Target) {
            $source->fill(['url' => $change->target->url, 'host' => $change->target->host, ...$forget]);
        }
        if ($change->changeLogin || $moved) {
            $source->fill(['auth_type' => $change->login?->type, 'credentials' => $change->login?->toArray(), ...$forget]);
        }
        if ($change->changeHostKey || $moved) {
            $source->fill(['host_key' => $change->hostKey]);
        }

        $source->save();
    }

    /**
     * Whether this login and this key may be used on this address.
     *
     * @throws ValidationException
     */
    public static function assertAllowed(Target $target, ?Credentials $login, ?string $hostKey): void
    {
        $refusal = $login?->refusalFor($target);
        if ($refusal !== null) {
            throw ValidationException::withMessages(['auth' => $refusal]);
        }

        if ($hostKey !== null && $target->scheme !== 'sftp') {
            throw ValidationException::withMessages(['host_key' => 'A server key only works on an sftp:// address.']);
        }
    }
}
