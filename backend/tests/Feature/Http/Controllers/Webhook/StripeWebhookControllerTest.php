<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Webhook;

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Webhook tests make direct POSTs to the public /api/webhooks/stripe endpoint
 * with Stripe-shaped event payloads. We disable signature verification for
 * the test environment so we can send fake events; in production it stays
 * required via STRIPE_WEBHOOK_SECRET.
 *
 * The test surface deliberately skips Cashier's own internal table updates
 * (subscriptions, subscription_items) — that logic lives in Cashier. What we
 * own and therefore test is the `teams.plan` sync.
 */
class StripeWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Disable signature verification so the webhook endpoint accepts raw JSON.
        config()->set('cashier.webhook.secret', null);

        // Make sure the tier config carries known test price ids so the
        // controller can map back from price → plan deterministically.
        config()->set('feedgenix.tiers.starter.stripe_monthly_id', 'price_starter_m');
        config()->set('feedgenix.tiers.starter.stripe_yearly_id',  'price_starter_y');
        config()->set('feedgenix.tiers.pro.stripe_monthly_id',     'price_pro_m');
        config()->set('feedgenix.tiers.pro.stripe_yearly_id',      'price_pro_y');
    }

    private function team(string $plan = 'free', string $stripeId = 'cus_test_123'): Team
    {
        return Team::create([
            'name' => 'Acme',
            'owner_id' => 1,
            'slug' => 'acme-' . uniqid(),
            'plan' => $plan,
            'stripe_id' => $stripeId,
        ]);
    }

    private function subscriptionUpdatedPayload(string $customerId, string $priceId, string $status = 'active'): array
    {
        return [
            'id' => 'evt_' . uniqid(),
            'type' => 'customer.subscription.updated',
            'data' => [
                'object' => [
                    'id' => 'sub_' . uniqid(),
                    'customer' => $customerId,
                    'status' => $status,
                    'items' => [
                        'data' => [
                            [
                                'id' => 'si_' . uniqid(),
                                'price' => ['id' => $priceId, 'product' => 'prod_x'],
                                'quantity' => 1,
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    #[Test]
    public function subscription_updated_promotes_team_to_matching_plan(): void
    {
        $team = $this->team('free', 'cus_test_abc');
        // Pre-seed the subscription row Cashier expects to exist.
        Subscription::create([
            'team_id' => $team->id,
            'type' => 'default',
            'stripe_id' => 'sub_existing',
            'stripe_status' => 'active',
            'stripe_price' => 'price_free',
            'quantity' => 1,
        ]);

        $payload = $this->subscriptionUpdatedPayload('cus_test_abc', 'price_pro_m');
        $payload['data']['object']['id'] = 'sub_existing';

        $this->postJson('/api/webhooks/stripe', $payload)->assertOk();

        $team->refresh();
        $this->assertSame('pro', $team->plan);
    }

    #[Test]
    public function subscription_updated_downgrades_team_when_switching_to_a_cheaper_plan(): void
    {
        $team = $this->team('pro', 'cus_downgrade_1');
        Subscription::create([
            'team_id' => $team->id,
            'type' => 'default',
            'stripe_id' => 'sub_downgrade',
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro_m',
            'quantity' => 1,
        ]);

        $payload = $this->subscriptionUpdatedPayload('cus_downgrade_1', 'price_starter_m');
        $payload['data']['object']['id'] = 'sub_downgrade';

        $this->postJson('/api/webhooks/stripe', $payload)->assertOk();

        $team->refresh();
        $this->assertSame('starter', $team->plan);
    }

    #[Test]
    public function subscription_updated_is_a_noop_when_plan_already_matches(): void
    {
        $team = $this->team('starter', 'cus_noop_1');
        Subscription::create([
            'team_id' => $team->id,
            'type' => 'default',
            'stripe_id' => 'sub_noop',
            'stripe_status' => 'active',
            'stripe_price' => 'price_starter_m',
            'quantity' => 1,
        ]);

        $originalUpdatedAt = $team->updated_at;

        $payload = $this->subscriptionUpdatedPayload('cus_noop_1', 'price_starter_m');
        $payload['data']['object']['id'] = 'sub_noop';

        // Nudge the clock so an accidental update would bump updated_at.
        $this->travel(2)->minutes();

        $this->postJson('/api/webhooks/stripe', $payload)->assertOk();

        $team->refresh();
        $this->assertSame('starter', $team->plan);
        $this->assertEquals(
            $originalUpdatedAt?->format('Y-m-d H:i:s'),
            $team->updated_at?->format('Y-m-d H:i:s'),
            'updated_at must not change when the plan did not change'
        );
    }

    #[Test]
    public function subscription_updated_ignores_unknown_price_ids_without_breaking(): void
    {
        $team = $this->team('starter', 'cus_unknown_price');
        Subscription::create([
            'team_id' => $team->id,
            'type' => 'default',
            'stripe_id' => 'sub_unknown',
            'stripe_status' => 'active',
            'stripe_price' => 'price_starter_m',
            'quantity' => 1,
        ]);

        $payload = $this->subscriptionUpdatedPayload('cus_unknown_price', 'price_not_in_config');
        $payload['data']['object']['id'] = 'sub_unknown';

        $this->postJson('/api/webhooks/stripe', $payload)->assertOk();

        $team->refresh();
        $this->assertSame('starter', $team->plan);   // no accidental change
    }

    #[Test]
    public function subscription_updated_ignores_payloads_for_unknown_customers(): void
    {
        $team = $this->team('free', 'cus_known');

        $payload = $this->subscriptionUpdatedPayload('cus_not_in_db', 'price_pro_m');

        $this->postJson('/api/webhooks/stripe', $payload)->assertOk();

        $team->refresh();
        $this->assertSame('free', $team->plan);
    }

    #[Test]
    public function subscription_deleted_resets_team_to_free(): void
    {
        $team = $this->team('pro', 'cus_cancel_1');
        Subscription::create([
            'team_id' => $team->id,
            'type' => 'default',
            'stripe_id' => 'sub_cancel',
            'stripe_status' => 'active',
            'stripe_price' => 'price_pro_m',
            'quantity' => 1,
        ]);

        $payload = [
            'id' => 'evt_' . uniqid(),
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_cancel',
                    'customer' => 'cus_cancel_1',
                    'status' => 'canceled',
                ],
            ],
        ];

        $this->postJson('/api/webhooks/stripe', $payload)->assertOk();

        $team->refresh();
        $this->assertSame('free', $team->plan);
    }

    #[Test]
    public function subscription_deleted_for_unknown_customer_is_a_safe_noop(): void
    {
        $team = $this->team('pro', 'cus_real');

        $payload = [
            'id' => 'evt_' . uniqid(),
            'type' => 'customer.subscription.deleted',
            'data' => ['object' => ['id' => 'sub_ghost', 'customer' => 'cus_ghost', 'status' => 'canceled']],
        ];

        $this->postJson('/api/webhooks/stripe', $payload)->assertOk();

        $team->refresh();
        $this->assertSame('pro', $team->plan);
    }
}
