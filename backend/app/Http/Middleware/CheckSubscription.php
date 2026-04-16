<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSubscription
{
    /**
     * Check that the team has a valid subscription or is on the free tier.
     *
     * Free tier users are always allowed through. Paid plans require an active
     * Stripe subscription (not cancelled, or within grace period).
     */
    public function handle(Request $request, Closure $next): Response
    {
        $team = $request->attributes->get('team');

        if (! $team) {
            abort(403, 'Team context is required.');
        }

        $plan = $team->plan ?? 'free';

        // Free tier always passes
        if ($plan === 'free') {
            return $next($request);
        }

        // Paid plans: check for active Cashier subscription
        $subscription = $team->subscription();

        if (! $subscription) {
            abort(403, 'No active subscription found. Please subscribe to a plan.');
        }

        if ($subscription->cancelled() && ! $subscription->onGracePeriod()) {
            abort(403, 'Your subscription has expired. Please renew to continue.');
        }

        return $next($request);
    }
}
