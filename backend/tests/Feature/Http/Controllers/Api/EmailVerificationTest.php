<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Api;

use App\Models\Team;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail as VerifyEmailNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Email verification is a hard gate: an unverified user can navigate the
 * app but cannot upload a CSV or fire a translation. These tests pin down:
 *
 *   - registration dispatches the Registered event so the framework's
 *     SendEmailVerificationNotification listener actually emails the user;
 *   - the click-handler accepts a properly-signed URL and rejects everything
 *     else (tampered hash, unknown user, expired signature);
 *   - resend is rate-limited so an attacker can't fill mailboxes;
 *   - protected routes 403 unverified users.
 */
class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Default to log mailer so notifications don't hit a real SMTP server.
        config()->set('mail.default', 'array');
    }

    private function makeUser(bool $verified = false): User
    {
        $team = Team::create([
            'name' => 'Acme',
            'owner_id' => 1,
            'slug' => 'acme-' . uniqid(),
            'plan' => 'starter',
            'products_used_this_month' => 0,
        ]);

        $user = User::create([
            'name' => 'Tester',
            'email' => 'tester-' . uniqid() . '@example.com',
            'password' => bcrypt('secret'),
            'team_id' => $team->id,
            'role' => 'owner',
        ]);
        if ($verified) {
            $user->markEmailAsVerified();
        }
        return $user;
    }

    private function signedVerifyUrl(User $user, ?Carbon $expiresAt = null): string
    {
        $expires = $expiresAt ?? now()->addMinutes(60);
        return URL::temporarySignedRoute(
            'verification.verify',
            $expires,
            [
                'id' => $user->id,
                'hash' => sha1($user->getEmailForVerification()),
            ],
        );
    }

    // ─── Registration → email dispatch ─────────────────────────────────────

    #[Test]
    public function register_fires_the_registered_event_so_verification_email_is_dispatched(): void
    {
        Event::fake([Registered::class]);

        $this->postJson('/api/auth/register', [
            'name' => 'Maria Pap',
            'email' => 'maria-' . uniqid() . '@example.com',
            'password' => 'SuperSecret-Pwd-99!',
            'password_confirmation' => 'SuperSecret-Pwd-99!',
        ])->assertCreated()
          ->assertJson(['email_verification_required' => true]);

        Event::assertDispatched(Registered::class);
    }

    #[Test]
    public function newly_registered_user_starts_unverified(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'X',
            'email' => 'x-' . uniqid() . '@example.com',
            'password' => 'SuperSecret-Pwd-99!',
            'password_confirmation' => 'SuperSecret-Pwd-99!',
        ])->assertCreated();

        $user = User::latest('id')->first();
        $this->assertNull($user->email_verified_at);
        $this->assertFalse($user->hasVerifiedEmail());
    }

    // ─── /api/email/verify/{id}/{hash} ─────────────────────────────────────

    #[Test]
    public function valid_signed_link_marks_email_verified_and_dispatches_verified_event(): void
    {
        Event::fake([Verified::class]);

        $user = $this->makeUser(verified: false);
        $url = $this->signedVerifyUrl($user);

        $this->getJson($url)
            ->assertOk()
            ->assertJson(['verified' => true]);

        $user->refresh();
        $this->assertTrue($user->hasVerifiedEmail());
        Event::assertDispatched(Verified::class);
    }

    #[Test]
    public function tampered_hash_is_rejected_even_with_a_valid_signature(): void
    {
        $user = $this->makeUser(verified: false);
        // Build a properly-signed URL but flip one character of the hash.
        $url = $this->signedVerifyUrl($user);
        $url = preg_replace('/\/verify\/(\d+)\/[a-f0-9]+/', '/verify/$1/0000000000000000000000000000000000000000', $url, 1);

        // The signature middleware will reject it (hash is part of the path
        // that gets signed), so we get 403 from the framework — exactly what
        // we want. We assert non-200 + still unverified.
        $resp = $this->getJson($url);
        $this->assertNotSame(200, $resp->status());

        $user->refresh();
        $this->assertFalse($user->hasVerifiedEmail());
    }

    #[Test]
    public function expired_signed_link_is_rejected(): void
    {
        $user = $this->makeUser(verified: false);
        // Issue a link with an explicit past expiry.
        $url = $this->signedVerifyUrl($user, expiresAt: now()->subMinute());

        $this->getJson($url)->assertStatus(403);

        $user->refresh();
        $this->assertFalse($user->hasVerifiedEmail());
    }

    #[Test]
    public function unknown_user_id_in_url_returns_403(): void
    {
        // Sign a URL for a non-existent user id. Note: since the user doesn't
        // exist, we can't compute their email hash — use a placeholder.
        $url = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => 999_999, 'hash' => str_repeat('0', 40)],
        );

        $this->getJson($url)->assertStatus(403);
    }

    #[Test]
    public function verifying_an_already_verified_user_is_idempotent(): void
    {
        $user = $this->makeUser(verified: true);
        $original = $user->email_verified_at;

        $url = $this->signedVerifyUrl($user);
        $this->getJson($url)->assertOk()->assertJson(['verified' => true]);

        // email_verified_at must NOT change on a no-op verification.
        $user->refresh();
        $this->assertEquals($original?->format('Y-m-d H:i:s'), $user->email_verified_at?->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function browser_click_redirects_to_frontend_success_page(): void
    {
        $user = $this->makeUser(verified: false);
        $url = $this->signedVerifyUrl($user);

        // Without `Accept: application/json`, the controller redirects.
        $resp = $this->get($url, ['Accept' => 'text/html']);
        $resp->assertRedirect();
        $location = $resp->headers->get('Location') ?? '';
        $this->assertStringContainsString('/email/verified', $location);
    }

    // ─── /api/email/verification-notification ──────────────────────────────

    #[Test]
    public function authenticated_unverified_user_can_request_a_resend(): void
    {
        Notification::fake();
        $user = $this->makeUser(verified: false);
        Sanctum::actingAs($user);

        $this->postJson('/api/email/verification-notification')
            ->assertOk()
            ->assertJson(['message' => 'Verification email sent.']);

        Notification::assertSentTo($user, VerifyEmailNotification::class);
    }

    #[Test]
    public function resend_is_rate_limited_to_six_per_minute(): void
    {
        Notification::fake();
        $user = $this->makeUser(verified: false);
        Sanctum::actingAs($user);

        for ($i = 0; $i < 6; $i++) {
            $this->postJson('/api/email/verification-notification')->assertOk();
        }
        // 7th request within the window must be throttled.
        $this->postJson('/api/email/verification-notification')->assertStatus(429);
    }

    #[Test]
    public function resend_for_already_verified_user_is_a_noop_and_does_not_send(): void
    {
        Notification::fake();
        $user = $this->makeUser(verified: true);
        Sanctum::actingAs($user);

        $this->postJson('/api/email/verification-notification')
            ->assertOk()
            ->assertJson(['message' => 'Already verified.']);

        Notification::assertNothingSent();
    }

    #[Test]
    public function unauthenticated_resend_request_is_rejected(): void
    {
        $this->postJson('/api/email/verification-notification')->assertStatus(401);
    }

    // ─── /api/email/status ─────────────────────────────────────────────────

    #[Test]
    public function status_endpoint_reports_verified_state(): void
    {
        $user = $this->makeUser(verified: false);
        Sanctum::actingAs($user);

        $this->getJson('/api/email/status')
            ->assertOk()
            ->assertJson(['verified' => false, 'email' => $user->email]);

        $user->markEmailAsVerified();

        $this->getJson('/api/email/status')
            ->assertOk()
            ->assertJson(['verified' => true]);
    }

    #[Test]
    public function status_requires_authentication(): void
    {
        $this->getJson('/api/email/status')->assertStatus(401);
    }

    // ─── Route gating: unverified users are blocked from upload + translate ──

    #[Test]
    public function unverified_user_cannot_upload_a_csv(): void
    {
        $user = $this->makeUser(verified: false);
        // Give the team a subscription so check.subscription doesn't muddy
        // this test — we want the FAILURE to come from `verified`, not from
        // CheckSubscription.
        \Laravel\Cashier\Subscription::create([
            'team_id' => $user->team_id,
            'type' => 'default',
            'stripe_id' => 'sub_' . uniqid(),
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);
        Sanctum::actingAs($user);

        $project = \App\Models\Project::create([
            'team_id' => $user->team_id,
            'name' => 'P',
            'status' => 'draft',
        ]);

        $resp = $this->postJson("/api/projects/{$project->id}/upload", []);

        $resp->assertStatus(403);
        $body = $resp->json();
        $this->assertSame('Your email address is not verified.', $body['message'] ?? null);
    }

    #[Test]
    public function unverified_user_cannot_start_a_translation(): void
    {
        $user = $this->makeUser(verified: false);
        \Laravel\Cashier\Subscription::create([
            'team_id' => $user->team_id,
            'type' => 'default',
            'stripe_id' => 'sub_' . uniqid(),
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);
        Sanctum::actingAs($user);

        $project = \App\Models\Project::create([
            'team_id' => $user->team_id,
            'name' => 'P',
            'status' => 'review',
        ]);

        $this->postJson("/api/projects/{$project->id}/translate")->assertStatus(403);
    }

    #[Test]
    public function verified_user_passes_the_email_gate_on_upload_endpoint(): void
    {
        $user = $this->makeUser(verified: true);
        \Laravel\Cashier\Subscription::create([
            'team_id' => $user->team_id,
            'type' => 'default',
            'stripe_id' => 'sub_' . uniqid(),
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);
        Sanctum::actingAs($user);

        $project = \App\Models\Project::create([
            'team_id' => $user->team_id,
            'name' => 'P',
            'status' => 'draft',
        ]);

        // No file → validation 422, NOT 403. That proves the verified gate
        // let the request through.
        $this->postJson("/api/projects/{$project->id}/upload", [])
            ->assertStatus(422);
    }
}
