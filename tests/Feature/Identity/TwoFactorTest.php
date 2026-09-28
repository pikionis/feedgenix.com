<?php

declare(strict_types=1);

use App\Domain\Identity\TwoFactor\TwoFactor;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    // The SPA's calls: same origin, so Sanctum starts the session the two-factor proof lives in.
    $this->withHeader('Referer', config('app.url').'/app');
});

it('keeps staff out of the admin area until two-factor sign-in is set up', function (): void {
    $this->actingAs(User::factory()->staff()->create())->getJson('/api/v1/admin/overview')
        ->assertForbidden()->assertJsonPath('error', 'two_factor_setup_required');
});

it('sets up two-factor with a QR drawn on our server, then shows the recovery codes once', function (): void {
    $staff = User::factory()->staff()->create();

    $enrolment = $this->actingAs($staff)->postJson('/api/v1/security/two-factor')->assertOk();

    expect($enrolment->json('qr_svg_data_url'))->toStartWith('data:image/svg+xml;base64,')
        ->and(base64_decode(substr((string) $enrolment->json('qr_svg_data_url'), 26), true))->toContain('<svg')
        ->and($enrolment->json('otpauth_url'))->toStartWith('otpauth://totp/FeedGenix:')
        ->and(DB::table('users')->where('id', $staff->id)->value('two_factor_secret'))->not->toBe($enrolment->json('secret'));

    $this->actingAs($staff)->postJson('/api/v1/security/two-factor/confirm', ['code' => '000000'])
        ->assertUnprocessable()->assertJsonValidationErrors('code');

    $codes = $this->actingAs($staff)->postJson('/api/v1/security/two-factor/confirm', ['code' => currentTotp($staff)])
        ->assertOk()->json('recovery_codes');

    $stored = json_decode((string) DB::table('users')->where('id', $staff->id)->value('two_factor_recovery_codes'), true);
    expect($codes)->toHaveCount(TwoFactor::RECOVERY_CODES)
        ->and(array_intersect($codes, $stored))->toBe([])
        ->and($stored[0])->toMatch('/^[0-9a-f]{64}$/');

    $this->actingAs($staff)->getJson('/api/v1/security/two-factor')
        ->assertExactJson(['enabled' => true, 'pending' => false, 'recovery_codes_left' => 8, 'passed' => true]);
});

it('does not start over once two-factor is set up', function (): void {
    $this->actingAs(staffWithTwoFactor())->postJson('/api/v1/security/two-factor')->assertStatus(409);
});

it('asks for a code in every new session, then opens the admin area', function (): void {
    $staff = staffWithTwoFactor();

    $this->actingAs($staff)->getJson('/api/v1/admin/overview')
        ->assertForbidden()->assertJsonPath('error', 'two_factor_required');

    $this->actingAs($staff)->postJson('/api/v1/security/two-factor/challenge', ['code' => currentTotp($staff)])->assertNoContent();

    $this->actingAs($staff)->getJson('/api/v1/admin/overview')->assertOk();
});

it('accepts each app code only once', function (): void {
    $staff = staffWithTwoFactor();
    $code = currentTotp($staff);

    $this->actingAs($staff)->postJson('/api/v1/security/two-factor/challenge', ['code' => $code])->assertNoContent();
    $this->actingAs($staff)->postJson('/api/v1/security/two-factor/challenge', ['code' => $code])->assertUnprocessable();
});

it('accepts each recovery code once, however it is typed', function (): void {
    $staff = User::factory()->staff()->create();
    $this->actingAs($staff)->postJson('/api/v1/security/two-factor');
    $codes = $this->actingAs($staff)->postJson('/api/v1/security/two-factor/confirm', ['code' => currentTotp($staff)])->json('recovery_codes');
    $this->flushSession();

    $this->actingAs($staff)->postJson('/api/v1/security/two-factor/challenge', ['code' => ' '.strtoupper($codes[0]).' '])->assertNoContent();
    $this->actingAs($staff)->postJson('/api/v1/security/two-factor/challenge', ['code' => $codes[0]])->assertUnprocessable();

    expect($staff->fresh()?->two_factor_recovery_codes)->toHaveCount(7);
});

it('rejects wrong codes and limits the attempts', function (): void {
    $staff = staffWithTwoFactor();

    foreach (range(1, 5) as $attempt) {
        $this->actingAs($staff)->postJson('/api/v1/security/two-factor/challenge', ['code' => '12345'.$attempt])->assertUnprocessable();
    }

    $this->actingAs($staff)->postJson('/api/v1/security/two-factor/challenge', ['code' => currentTotp($staff)])->assertTooManyRequests();
});

it('forgets the proof after twelve hours', function (): void {
    $staff = staffWithTwoFactor();
    $this->actingAs($staff)->withSession(twoFactorPassedSession())->getJson('/api/v1/admin/overview')->assertOk();

    $this->travel(13)->hours();

    $this->actingAs($staff)->getJson('/api/v1/admin/overview')->assertForbidden()->assertJsonPath('error', 'two_factor_required');
});

it('is for staff only for now', function (): void {
    $customer = User::factory()->withWorkspace()->create();

    $this->actingAs($customer)->getJson('/api/v1/security/two-factor')->assertForbidden();
    $this->actingAs($customer)->postJson('/api/v1/security/two-factor')->assertForbidden();
    $this->actingAs($customer)->getJson('/api/v1/admin/overview')->assertForbidden();
});

it('never shows the secret or the codes after setup', function (): void {
    $staff = staffWithTwoFactor();

    $status = (string) $this->actingAs($staff)->getJson('/api/v1/security/two-factor')->getContent();
    $user = (string) $this->actingAs($staff)->getJson('/api/v1/auth/user')->getContent();

    expect($status)->not->toContain((string) $staff->fresh()?->two_factor_secret)
        ->and($user)->not->toContain('two_factor');
});

it('renews the recovery codes only after the proof, and the old ones stop working', function (): void {
    $staff = staffWithTwoFactor();
    $this->actingAs($staff)->postJson('/api/v1/security/two-factor/recovery-codes')->assertForbidden();

    $first = $this->actingAs($staff)->withSession(twoFactorPassedSession())->postJson('/api/v1/security/two-factor/recovery-codes')->assertOk()->json('recovery_codes');
    $this->actingAs($staff)->postJson('/api/v1/security/two-factor/recovery-codes')->assertOk();
    $this->flushSession();

    $this->actingAs($staff)->postJson('/api/v1/security/two-factor/challenge', ['code' => $first[0]])->assertUnprocessable();
});

it('shows staff platform-wide counts across every workspace', function (): void {
    addLanguage(Tenant::factory()->create(), 'de');
    addLanguage(Tenant::factory()->onPlan('pro')->create(), 'fr');

    $this->actingAs(staffWithTwoFactor())->withSession(twoFactorPassedSession())->getJson('/api/v1/admin/overview')
        ->assertOk()
        ->assertJsonPath('workspaces', 2)
        ->assertJsonPath('paid_workspaces', 1)
        ->assertJsonPath('target_languages', 2);
});
