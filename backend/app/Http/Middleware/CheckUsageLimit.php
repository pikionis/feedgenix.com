<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckUsageLimit
{
    /**
     * Reject requests when the team has hit its monthly billable-product cap.
     *
     * Limits are resolved through Team::effectiveLimits() so trial users see
     * the trial cap (currently 20 products) instead of the underlying plan's
     * cap. Cache-only products do NOT count against this limit — RunTranslation
     * only increments products_used_this_month for products that actually
     * consumed an AI call.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $team = $request->attributes->get('team');

        if (! $team) {
            abort(403, 'Team context is required.');
        }

        $limits = $team->effectiveLimits();
        $limit = (int) ($limits['products_per_month'] ?? 0);
        $used = (int) ($team->products_used_this_month ?? 0);

        if ($used >= $limit) {
            $context = $team->isOnTrial() ? 'trial' : ((string) ($team->plan ?? 'plan') . ' plan');
            abort(429, "Monthly product limit reached ({$used}/{$limit}). Upgrade your {$context} to continue translating.");
        }

        return $next($request);
    }
}
