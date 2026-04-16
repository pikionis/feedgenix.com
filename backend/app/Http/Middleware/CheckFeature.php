<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckFeature
{
    /**
     * Check if the team's current plan includes the requested feature.
     *
     * Usage in routes: ->middleware('check.feature:smart_mapping')
     *
     * Enterprise plans with ['*'] have access to all features.
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $team = $request->attributes->get('team');

        if (! $team) {
            abort(403, 'Team context is required.');
        }

        $plan = $team->plan ?? 'free';
        $tierConfig = config("feedgenix.tiers.{$plan}", config('feedgenix.tiers.free'));
        $allowedFeatures = $tierConfig['features'] ?? [];

        // Enterprise wildcard: ['*'] grants access to everything
        if (in_array('*', $allowedFeatures, true)) {
            return $next($request);
        }

        if (! in_array($feature, $allowedFeatures, true)) {
            abort(403, "Your {$plan} plan does not include the '{$feature}' feature. Please upgrade.");
        }

        return $next($request);
    }
}
