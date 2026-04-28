<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeature
{
    /**
     * Reject requests for features outside the team's effective feature set.
     *
     * Usage in routes: ->middleware('check.feature:smart_mapping')
     *
     * Effective features come from Team::effectiveLimits() so trial users see
     * the trial feature list (typically a strict subset of the underlying
     * plan's features) rather than the plan's full set.
     *
     * Enterprise plans with ['*'] grant every feature, current and future.
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $team = $request->attributes->get('team');

        if (! $team) {
            abort(403, 'Team context is required.');
        }

        $limits = $team->effectiveLimits();
        $allowedFeatures = (array) ($limits['features'] ?? []);

        if (in_array('*', $allowedFeatures, true)) {
            return $next($request);
        }

        if (! in_array($feature, $allowedFeatures, true)) {
            $context = $team->isOnTrial() ? 'trial' : ((string) ($team->plan ?? 'plan') . ' plan');
            abort(403, "Your {$context} does not include the '{$feature}' feature. Please upgrade.");
        }

        return $next($request);
    }
}
