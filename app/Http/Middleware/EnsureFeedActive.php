<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Domain\Billing\Entitlements;
use App\Domain\Billing\PlanLimitExceeded;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Ingest\Models\Import;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * What makes a feed do work (a new file, a fetch, reading it again, AI suggestions, a translation)
 * answers 402 while the plan has the feed paused (ported from the legacy EnsureFeedActive).
 * Looking, editing the mapping and texts, and its exports stay open.
 */
final class EnsureFeedActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $feed = $request->route('feed');
        $import = $request->route('import');
        if (! $feed instanceof Feed && $import instanceof Import) {
            $feed = Feed::query()->find($import->feed_id);
        }
        if ($feed instanceof Feed && $feed->plan_paused_at !== null) {
            $entitlements = Entitlements::for($feed->tenant_id);
            $allowed = $entitlements->limit('feeds') ?? 0;
            $used = $entitlements->used('feeds');

            throw PlanLimitExceeded::feedPaused($entitlements->plan(), $allowed, $used, $entitlements->upgradeFor('feeds', $used));
        }

        return $next($request);
    }
}
