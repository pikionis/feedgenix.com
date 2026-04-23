<?php

declare(strict_types=1);

namespace App\Http\Controllers\Webhook;

use App\Models\Team;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Http\Controllers\WebhookController;

/**
 * Stripe webhook handler. Inherits the default Cashier dispatcher and
 * additionally keeps `teams.plan` in sync with the active Stripe price —
 * so downstream feature/usage middleware has an accurate plan to check
 * without rereading Stripe on every request.
 *
 * We map Stripe price IDs back to our internal plan names via config:
 *   config('feedgenix.tiers.<plan>.stripe_monthly_id')
 *   config('feedgenix.tiers.<plan>.stripe_yearly_id')
 *
 * On subscription creation / upgrade / downgrade → set the team's plan.
 * On cancellation (deleted event)                 → fall the team back to 'free'.
 */
class StripeWebhookController extends WebhookController
{
    /**
     * Called by Cashier when Stripe sends `customer.subscription.created`
     * or `customer.subscription.updated`. Cashier has already synced the
     * subscription row by the time this runs — we only handle the plan cache.
     */
    protected function handleCustomerSubscriptionUpdated(array $payload)
    {
        // Run our own plan sync BEFORE handing off to Cashier's default
        // handler. That way we're not dependent on the parent returning
        // cleanly (it can `return null;` on incomplete-expired events).
        $this->syncTeamPlanFromPayload($payload);
        return parent::handleCustomerSubscriptionUpdated($payload);
    }

    /**
     * Called when Stripe cancels a subscription (`customer.subscription.deleted`).
     * Resets the team back to the free tier so feature checks immediately
     * stop granting paid access.
     */
    protected function handleCustomerSubscriptionDeleted(array $payload)
    {
        $team = $this->resolveTeam($payload);
        if ($team) {
            $team->update(['plan' => 'free']);
            Log::info('Stripe webhook: team downgraded to free', ['team_id' => $team->id]);
        }

        return parent::handleCustomerSubscriptionDeleted($payload);
    }

    private function syncTeamPlanFromPayload(array $payload): void
    {
        $team = $this->resolveTeam($payload);
        if (!$team) {
            return;
        }

        $priceId = $this->extractActivePriceId($payload);
        if (!$priceId) {
            return;
        }

        $plan = $this->planForPriceId($priceId);
        if (!$plan) {
            Log::warning('Stripe webhook: price id not mapped to a plan', [
                'team_id' => $team->id,
                'stripe_price' => $priceId,
            ]);
            return;
        }

        if ($team->plan !== $plan) {
            $previous = $team->plan;
            $team->update(['plan' => $plan]);
            Log::info('Stripe webhook: team plan updated', [
                'team_id' => $team->id,
                'from' => $previous,
                'to' => $plan,
            ]);
        }
    }

    /**
     * Resolve the Team record from the customer id in the event payload.
     * The event's `data.object.customer` matches `teams.stripe_id`.
     */
    private function resolveTeam(array $payload): ?Team
    {
        $customerId = $payload['data']['object']['customer'] ?? null;
        if (!is_string($customerId) || $customerId === '') {
            return null;
        }
        return Team::where('stripe_id', $customerId)->first();
    }

    /**
     * Pick the first price id off the subscription payload. Stripe packs
     * them inside `items.data[].price.id` for multi-item subs; we currently
     * only ever have one item per subscription.
     */
    private function extractActivePriceId(array $payload): ?string
    {
        $items = $payload['data']['object']['items']['data'] ?? [];
        if (!is_array($items) || empty($items)) {
            return null;
        }
        $priceId = $items[0]['price']['id'] ?? null;
        return is_string($priceId) && $priceId !== '' ? $priceId : null;
    }

    private function planForPriceId(string $priceId): ?string
    {
        foreach ((array) config('feedgenix.tiers', []) as $plan => $tier) {
            foreach (['stripe_monthly_id', 'stripe_yearly_id'] as $key) {
                if (($tier[$key] ?? null) === $priceId) {
                    return (string) $plan;
                }
            }
        }
        return null;
    }
}
