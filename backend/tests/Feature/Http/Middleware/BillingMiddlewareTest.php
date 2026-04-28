<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The three billing-gate middlewares run in front of every write endpoint
 * in the app. If any of them misfires the whole product leaks. Tests hit
 * them through real routes so we also cover the order in which they chain
 * (scope.team → check.subscription → check.usage, etc.).
 */
class BillingMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Fake endpoints scoped through each middleware. Declared once per
        // test case so every test has a fresh router state.
        Route::middleware(['auth:sanctum', 'scope.team'])->group(function () {
            Route::get('/test/subscription', fn () => response()->json(['ok' => true]))
                ->middleware('check.subscription');

            Route::get('/test/usage', fn () => response()->json(['ok' => true]))
                ->middleware('check.usage');

            Route::get('/test/feature/mapping', fn () => response()->json(['ok' => true]))
                ->middleware('check.feature:smart_mapping');

            Route::get('/test/feature/csv', fn () => response()->json(['ok' => true]))
                ->middleware('check.feature:csv_upload');
        });
    }

    private function actAs(string $plan = 'free', array $teamOverrides = []): User
    {
        $team = Team::create(array_merge([
            'name' => 'Acme',
            'owner_id' => 1,
            'slug' => 'acme-' . uniqid(),
            'plan' => $plan,
            'products_used_this_month' => 0,
        ], $teamOverrides));

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

    // ─── CheckSubscription ────────────────────────────────────────────────

    #[Test]
    public function check_subscription_lets_the_free_tier_through_even_without_a_stripe_subscription(): void
    {
        $this->actAs('free');
        $this->getJson('/test/subscription')->assertOk();
    }

    #[Test]
    public function check_subscription_rejects_a_paid_tier_that_has_no_subscription_record(): void
    {
        $this->actAs('pro');
        $this->getJson('/test/subscription')
            ->assertForbidden()
            ->assertJson(['message' => 'No active subscription found. Please subscribe to a plan.']);
    }

    #[Test]
    public function check_subscription_rejects_a_request_without_a_team_context(): void
    {
        // User with no team_id — scope.team runs first and rejects.
        $user = User::create([
            'name' => 'Orphan',
            'email' => 'orphan@example.com',
            'password' => bcrypt('secret'),
            'team_id' => null,
            'role' => 'member',
        ]);
        Sanctum::actingAs($user);

        $this->getJson('/test/subscription')->assertForbidden();
    }

    // ─── CheckUsageLimit ──────────────────────────────────────────────────

    #[Test]
    public function check_usage_lets_a_team_within_its_quota_through(): void
    {
        // Starter cap is 10,000 products / month under the new pricing model.
        $this->actAs('starter', ['products_used_this_month' => 5_000]);
        $this->getJson('/test/usage')->assertOk();
    }

    #[Test]
    public function check_usage_returns_429_when_the_monthly_quota_is_reached(): void
    {
        $this->actAs('starter', ['products_used_this_month' => 10_000]);  // at limit
        $this->getJson('/test/usage')
            ->assertStatus(429)
            ->assertJson(['message' => 'Monthly product limit reached (10000/10000). Upgrade your starter plan to continue translating.']);
    }

    #[Test]
    public function check_usage_returns_429_when_the_monthly_quota_is_exceeded(): void
    {
        $this->actAs('starter', ['products_used_this_month' => 20_000]);
        $this->getJson('/test/usage')->assertStatus(429);
    }

    #[Test]
    public function check_usage_on_free_tier_caps_at_500_products_per_config(): void
    {
        $this->actAs('free', ['products_used_this_month' => 500]);
        $this->getJson('/test/usage')->assertStatus(429);
    }

    #[Test]
    public function check_usage_never_blocks_enterprise_because_limit_is_php_int_max(): void
    {
        $this->actAs('enterprise', ['products_used_this_month' => 5_000_000]);
        $this->getJson('/test/usage')->assertOk();
    }

    // ─── CheckFeature ─────────────────────────────────────────────────────

    #[Test]
    public function check_feature_allows_a_feature_included_in_the_plan(): void
    {
        // csv_upload is in every tier per config.
        $this->actAs('free');
        $this->getJson('/test/feature/csv')->assertOk();
    }

    #[Test]
    public function check_feature_denies_a_feature_not_included_in_the_plan(): void
    {
        // smart_mapping is NOT on the free tier (only basic_mapping is).
        $this->actAs('free');
        $this->getJson('/test/feature/mapping')
            ->assertForbidden()
            ->assertJson(['message' => "Your free plan does not include the 'smart_mapping' feature. Please upgrade."]);
    }

    #[Test]
    public function check_feature_grants_every_feature_to_enterprise_via_wildcard(): void
    {
        $this->actAs('enterprise');
        $this->getJson('/test/feature/mapping')->assertOk();
        $this->getJson('/test/feature/csv')->assertOk();
    }

    #[Test]
    public function check_feature_denies_when_plan_has_no_feature_list(): void
    {
        // An unknown plan falls back to the "free" tier list per middleware default.
        $this->actAs('mystery');
        $this->getJson('/test/feature/mapping')->assertForbidden();
    }

    // ─── Trial-aware behavior ────────────────────────────────────────────

    private function attachTrial(\App\Models\Team $team, ?\DateTimeInterface $trialEndsAt = null): void
    {
        \Laravel\Cashier\Subscription::create([
            'team_id' => $team->id,
            'type' => 'default',
            'stripe_id' => 'sub_' . uniqid(),
            'stripe_status' => 'trialing',
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'trial_ends_at' => $trialEndsAt ?? now()->addDays(5),
        ]);
    }

    #[Test]
    public function trial_caps_usage_at_20_products_even_for_a_starter_subscriber(): void
    {
        $user = $this->actAs('starter', ['products_used_this_month' => 20]);
        $this->attachTrial($user->team);
        // 20 products is the trial cap — at limit, request is blocked even
        // though the underlying Starter plan would allow 10K.
        $this->getJson('/test/usage')
            ->assertStatus(429)
            ->assertJson(['message' => 'Monthly product limit reached (20/20). Upgrade your trial to continue translating.']);
    }

    #[Test]
    public function trial_users_below_the_20_product_cap_pass_through(): void
    {
        $user = $this->actAs('starter', ['products_used_this_month' => 19]);
        $this->attachTrial($user->team);
        $this->getJson('/test/usage')->assertOk();
    }

    #[Test]
    public function trial_features_block_smart_mapping_even_for_starter_subscribers(): void
    {
        // Starter normally has smart_mapping; the trial overlay does NOT.
        // Until the trial ends, the user is gated.
        $user = $this->actAs('starter');
        $this->attachTrial($user->team);
        $this->getJson('/test/feature/mapping')
            ->assertForbidden()
            ->assertJson(['message' => "Your trial does not include the 'smart_mapping' feature. Please upgrade."]);
    }

    #[Test]
    public function once_trial_ends_starter_features_kick_in(): void
    {
        $user = $this->actAs('starter');
        \Laravel\Cashier\Subscription::create([
            'team_id' => $user->team->id,
            'type' => 'default',
            'stripe_id' => 'sub_' . uniqid(),
            'stripe_status' => 'active',                 // post-trial
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'trial_ends_at' => now()->subDay(),         // trial ended yesterday
        ]);

        // smart_mapping is included in the Starter feature list.
        $this->getJson('/test/feature/mapping')->assertOk();
    }
}
