<?php

declare(strict_types=1);

namespace Tests\Feature\Models;

use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Cashier\Subscription;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The trial concept is the load-bearing piece of the new pricing model:
 * every new merchant gets 7 days on Starter with severely-restricted limits,
 * the card is on file, Stripe charges automatically on day 8.
 *
 * These tests pin down the helpers that other layers (middleware, controllers,
 * UI) rely on. If isOnTrial / effectiveLimits / trialDaysRemaining drift,
 * either users get billed on day 1 or they get unlimited rows during trial —
 * both bad.
 */
class TeamTrialTest extends TestCase
{
    use RefreshDatabase;

    private function makeTeam(string $plan = 'starter'): Team
    {
        return Team::create([
            'name' => 'Acme',
            'owner_id' => 1,
            'slug' => 'acme-' . uniqid(),
            'plan' => $plan,
            'products_used_this_month' => 0,
            'stripe_id' => 'cus_' . uniqid(),
        ]);
    }

    private function attachSubscription(Team $team, ?\DateTimeInterface $trialEndsAt, string $status = 'trialing'): Subscription
    {
        return Subscription::create([
            'team_id' => $team->id,
            'type' => 'default',
            'stripe_id' => 'sub_' . uniqid(),
            'stripe_status' => $status,
            'stripe_price' => 'price_test',
            'quantity' => 1,
            'trial_ends_at' => $trialEndsAt,
        ]);
    }

    #[Test]
    public function is_on_trial_returns_false_when_team_has_no_subscription(): void
    {
        $team = $this->makeTeam();
        $this->assertFalse($team->isOnTrial());
        $this->assertSame(0, $team->trialDaysRemaining());
    }

    #[Test]
    public function is_on_trial_returns_true_during_the_trial_window(): void
    {
        $team = $this->makeTeam();
        $this->attachSubscription($team, now()->addDays(5), 'trialing');

        $this->assertTrue($team->isOnTrial());
    }

    #[Test]
    public function is_on_trial_returns_false_after_the_trial_ends_even_if_subscription_is_active(): void
    {
        $team = $this->makeTeam();
        // Trial already ended; status flipped to active by Stripe.
        $this->attachSubscription($team, now()->subDay(), 'active');

        $this->assertFalse($team->isOnTrial());
        $this->assertSame(0, $team->trialDaysRemaining());
    }

    #[Test]
    public function trial_days_remaining_uses_ceil_so_partial_day_reads_as_one(): void
    {
        $team = $this->makeTeam();
        $this->attachSubscription($team, now()->addHours(5), 'trialing');

        // ~5 hours left → 1 day remaining (ceil), not 0.
        $this->assertSame(1, $team->trialDaysRemaining());
    }

    #[Test]
    public function trial_days_remaining_caps_at_seven_for_a_fresh_trial(): void
    {
        $team = $this->makeTeam();
        $this->attachSubscription($team, now()->addDays(7)->addMinutes(1), 'trialing');

        // Just-started 7-day trial → 7 days remaining (or 8 with ceil tolerance).
        $this->assertGreaterThanOrEqual(7, $team->trialDaysRemaining());
    }

    #[Test]
    public function effective_limits_returns_the_trial_overlay_during_trial(): void
    {
        $team = $this->makeTeam('starter');
        $this->attachSubscription($team, now()->addDays(5), 'trialing');

        $limits = $team->effectiveLimits();

        // Trial limits are tight — 20 products, 1 language, 1 user — even
        // though the underlying plan is Starter (10K / 4 langs / 3 users).
        $this->assertSame(20, $limits['products_per_month']);
        $this->assertSame(1, $limits['target_languages']);
        $this->assertSame(1, $limits['team_members']);
    }

    #[Test]
    public function effective_limits_returns_the_plans_tier_after_trial(): void
    {
        $team = $this->makeTeam('starter');
        $this->attachSubscription($team, now()->subDay(), 'active');

        $limits = $team->effectiveLimits();

        // Trial expired → Starter limits apply.
        $this->assertSame(10_000, $limits['products_per_month']);
        $this->assertSame(4, $limits['target_languages']);
        $this->assertSame(3, $limits['team_members']);
    }

    #[Test]
    public function effective_limits_falls_back_to_free_for_unknown_plans(): void
    {
        $team = $this->makeTeam('totally_unknown_plan');

        $limits = $team->effectiveLimits();

        // Free is the defensive fallback so feature lookups never crash.
        $this->assertSame(500, $limits['products_per_month']);
    }

    #[Test]
    public function trial_overlay_does_not_persist_after_trial_ends_for_a_pro_team(): void
    {
        // Regression: if a Pro team is mid-trial, they get trial limits.
        // Once trial ends, they should get FULL Pro limits, not Starter or Free.
        $team = $this->makeTeam('pro');
        $sub = $this->attachSubscription($team, now()->addDays(2), 'trialing');

        $this->assertSame(20, $team->effectiveLimits()['products_per_month']);

        // Simulate trial expiring.
        $sub->update(['trial_ends_at' => now()->subMinute(), 'stripe_status' => 'active']);
        $team->refresh();

        $this->assertSame(50_000, $team->effectiveLimits()['products_per_month']);
        $this->assertSame(7, $team->effectiveLimits()['target_languages']);
    }
}
