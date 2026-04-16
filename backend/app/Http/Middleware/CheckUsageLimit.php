<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUsageLimit
{
    /**
     * Check if the team has exceeded their monthly product usage limit.
     *
     * Compares team.products_used_this_month against the plan's products_per_month.
     * Returns 429 (Too Many Requests) if the limit is exceeded.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $team = $request->attributes->get('team');

        if (! $team) {
            abort(403, 'Team context is required.');
        }

        $plan = $team->plan ?? 'free';
        $tierConfig = config("feedgenix.tiers.{$plan}", config('feedgenix.tiers.free'));
        $limit = $tierConfig['products_per_month'] ?? 0;

        $used = $team->products_used_this_month ?? 0;

        if ($used >= $limit) {
            abort(429, "Monthly product limit reached ({$used}/{$limit}). Please upgrade your plan.");
        }

        return $next($request);
    }
}
