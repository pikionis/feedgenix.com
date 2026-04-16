<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SubscriptionController extends Controller
{
    /**
     * Return the current team's subscription info and plan details.
     */
    public function show(Request $request): JsonResponse
    {
        /** @var Team $team */
        $team = $request->attributes->get('team');
        $plan = $team->plan ?? 'free';
        $tierConfig = config("feedgenix.tiers.{$plan}", config('feedgenix.tiers.free'));

        $subscription = $team->subscription();

        return response()->json([
            'plan' => $plan,
            'tier' => $tierConfig,
            'products_used_this_month' => $team->products_used_this_month,
            'subscription' => $subscription ? [
                'stripe_status' => $subscription->stripe_status,
                'ends_at' => $subscription->ends_at,
                'trial_ends_at' => $subscription->trial_ends_at,
                'on_grace_period' => $subscription->onGracePeriod(),
            ] : null,
        ]);
    }

    /**
     * Create a Stripe checkout session for a new subscription.
     */
    public function create(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', 'in:starter,pro,enterprise'],
            'billing_cycle' => ['required', 'string', 'in:monthly,yearly'],
        ]);

        /** @var Team $team */
        $team = $request->attributes->get('team');
        $plan = $validated['plan'];
        $cycle = $validated['billing_cycle'];

        $priceKey = $cycle === 'yearly' ? 'stripe_yearly_id' : 'stripe_monthly_id';
        $priceId = config("feedgenix.tiers.{$plan}.{$priceKey}");

        if (! $priceId) {
            return response()->json([
                'message' => 'Invalid plan or billing cycle configuration.',
            ], 422);
        }

        $checkout = $team->newSubscription('default', $priceId)
            ->checkout([
                'success_url' => config('app.frontend_url') . '/billing?success=true',
                'cancel_url' => config('app.frontend_url') . '/billing?cancelled=true',
            ]);

        return response()->json([
            'checkout_url' => $checkout->url,
        ]);
    }

    /**
     * Swap the team's subscription to a different plan.
     */
    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'plan' => ['required', 'string', 'in:starter,pro,enterprise'],
            'billing_cycle' => ['required', 'string', 'in:monthly,yearly'],
        ]);

        /** @var Team $team */
        $team = $request->attributes->get('team');
        $plan = $validated['plan'];
        $cycle = $validated['billing_cycle'];

        $priceKey = $cycle === 'yearly' ? 'stripe_yearly_id' : 'stripe_monthly_id';
        $priceId = config("feedgenix.tiers.{$plan}.{$priceKey}");

        if (! $priceId) {
            return response()->json([
                'message' => 'Invalid plan or billing cycle configuration.',
            ], 422);
        }

        $subscription = $team->subscription();

        if (! $subscription) {
            return response()->json([
                'message' => 'No active subscription found. Please create one first.',
            ], 404);
        }

        $subscription->swap($priceId);

        $team->update(['plan' => $plan]);

        return response()->json([
            'message' => 'Subscription updated successfully.',
            'plan' => $plan,
        ]);
    }

    /**
     * Cancel the team's subscription.
     */
    public function cancel(Request $request): JsonResponse
    {
        /** @var Team $team */
        $team = $request->attributes->get('team');

        $subscription = $team->subscription();

        if (! $subscription) {
            return response()->json([
                'message' => 'No active subscription found.',
            ], 404);
        }

        $subscription->cancel();

        return response()->json([
            'message' => 'Subscription cancelled. It will remain active until the end of the billing period.',
            'ends_at' => $subscription->ends_at,
        ]);
    }

    /**
     * Return the Stripe billing portal URL for the team.
     */
    public function portal(Request $request): JsonResponse
    {
        /** @var Team $team */
        $team = $request->attributes->get('team');

        $portalUrl = $team->billingPortalUrl(
            config('app.frontend_url') . '/billing',
        );

        return response()->json([
            'portal_url' => $portalUrl,
        ]);
    }
}
