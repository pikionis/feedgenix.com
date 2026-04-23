<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Subscription;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Tests focus on what we can verify without hitting Stripe — the show()
 * endpoint and the cancel() endpoint with a locally-created subscription row.
 * Create/update/portal call Stripe; we cover their validation paths and skip
 * the actual API calls, which live behind an external dependency we mock only
 * at integration time.
 */
class SubscriptionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actAs(string $plan = 'free', array $overrides = []): User
    {
        $team = Team::create(array_merge([
            'name' => 'Acme',
            'owner_id' => 1,
            'slug' => 'acme-' . uniqid(),
            'plan' => $plan,
            'products_used_this_month' => 0,
        ], $overrides));

        $user = User::create([
            'name' => 'Tester',
            'email' => 'tester-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'team_id' => $team->id,
            'role' => 'owner',
        ]);

        Sanctum::actingAs($user);
        return $user;
    }

    private function attachSubscription(Team $team, string $stripeStatus = 'active'): Subscription
    {
        return Subscription::create([
            'team_id' => $team->id,
            'type' => 'default',
            'stripe_id' => 'sub_' . uniqid(),
            'stripe_status' => $stripeStatus,
            'stripe_price' => 'price_starter_monthly',
            'quantity' => 1,
        ]);
    }

    #[Test]
    public function show_returns_free_tier_details_without_a_subscription(): void
    {
        $this->actAs('free');

        $this->getJson('/api/subscription')
            ->assertOk()
            ->assertJson([
                'plan' => 'free',
                'products_used_this_month' => 0,
                'subscription' => null,
            ])
            ->assertJsonPath('tier.products_per_month', 100);
    }

    #[Test]
    public function show_returns_current_usage_and_tier_features(): void
    {
        $user = $this->actAs('starter', ['products_used_this_month' => 423]);
        $this->attachSubscription($user->team, 'active');

        $this->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('plan', 'starter')
            ->assertJsonPath('products_used_this_month', 423)
            ->assertJsonPath('subscription.stripe_status', 'active')
            ->assertJsonPath('tier.products_per_month', 1000)
            ->assertJsonStructure(['tier' => ['features', 'team_members', 'target_languages']]);
    }

    #[Test]
    public function show_falls_back_to_free_tier_config_for_unknown_plans(): void
    {
        $this->actAs('some_weird_plan');

        $this->getJson('/api/subscription')
            ->assertOk()
            ->assertJsonPath('tier.products_per_month', 100);   // free-tier default
    }

    #[Test]
    public function show_requires_authentication(): void
    {
        $this->getJson('/api/subscription')->assertStatus(401);
    }

    #[Test]
    public function show_rejects_a_user_without_a_team(): void
    {
        $orphan = User::create([
            'name' => 'Orphan',
            'email' => 'orphan@example.com',
            'password' => bcrypt('secret'),
            'team_id' => null,
            'role' => 'member',
        ]);
        Sanctum::actingAs($orphan);

        $this->getJson('/api/subscription')->assertForbidden();
    }

    // ─── create() validation ──────────────────────────────────────────────

    #[Test]
    public function create_rejects_unknown_plans(): void
    {
        $this->actAs('free');

        $this->postJson('/api/subscription', [
            'plan' => 'platinum',           // not in the enum
            'billing_cycle' => 'monthly',
        ])->assertStatus(422)
          ->assertJsonValidationErrors('plan');
    }

    #[Test]
    public function create_rejects_unknown_billing_cycles(): void
    {
        $this->actAs('free');

        $this->postJson('/api/subscription', [
            'plan' => 'starter',
            'billing_cycle' => 'biennial',
        ])->assertStatus(422)
          ->assertJsonValidationErrors('billing_cycle');
    }

    #[Test]
    public function create_returns_422_when_stripe_price_is_not_configured(): void
    {
        config()->set('feedgenix.tiers.starter.stripe_monthly_id', null);
        $this->actAs('free');

        $this->postJson('/api/subscription', [
            'plan' => 'starter',
            'billing_cycle' => 'monthly',
        ])->assertStatus(422)
          ->assertJson(['message' => 'Invalid plan or billing cycle configuration.']);
    }

    // ─── update() validation ──────────────────────────────────────────────

    #[Test]
    public function update_returns_404_when_there_is_no_subscription_to_swap(): void
    {
        config()->set('feedgenix.tiers.pro.stripe_monthly_id', 'price_pro_monthly_test');
        $this->actAs('starter');

        $this->putJson('/api/subscription', [
            'plan' => 'pro',
            'billing_cycle' => 'monthly',
        ])->assertStatus(404)
          ->assertJson(['message' => 'No active subscription found. Please create one first.']);
    }

    // ─── cancel() ─────────────────────────────────────────────────────────

    #[Test]
    public function cancel_returns_404_when_there_is_no_subscription(): void
    {
        $this->actAs('starter');

        $this->deleteJson('/api/subscription')
            ->assertStatus(404)
            ->assertJson(['message' => 'No active subscription found.']);
    }
}
