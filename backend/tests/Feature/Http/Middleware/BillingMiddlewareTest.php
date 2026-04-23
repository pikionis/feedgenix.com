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
        $this->actAs('starter', ['products_used_this_month' => 500]);  // limit is 1000
        $this->getJson('/test/usage')->assertOk();
    }

    #[Test]
    public function check_usage_returns_429_when_the_monthly_quota_is_reached(): void
    {
        $this->actAs('starter', ['products_used_this_month' => 1000]);  // at limit
        $this->getJson('/test/usage')
            ->assertStatus(429)
            ->assertJson(['message' => 'Monthly product limit reached (1000/1000). Please upgrade your plan.']);
    }

    #[Test]
    public function check_usage_returns_429_when_the_monthly_quota_is_exceeded(): void
    {
        $this->actAs('starter', ['products_used_this_month' => 2_000]);
        $this->getJson('/test/usage')->assertStatus(429);
    }

    #[Test]
    public function check_usage_on_free_tier_caps_at_100_products_per_config(): void
    {
        $this->actAs('free', ['products_used_this_month' => 100]);
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
}
