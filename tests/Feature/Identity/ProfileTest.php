<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

it('updates the name and the interface language', function (): void {
    $user = User::factory()->create();

    $this->actingAs($user)->putJson('/api/v1/profile', ['name' => 'Renamed', 'locale' => 'el'])
        ->assertOk()->assertJsonPath('user.name', 'Renamed')->assertJsonPath('user.locale', 'el');
});

it('rejects an unknown language', function (): void {
    $this->actingAs(User::factory()->create())->putJson('/api/v1/profile', ['locale' => 'xx-YY'])
        ->assertUnprocessable()->assertJsonValidationErrors('locale');
});

it('cannot change the email, staff access, verification or workspace', function (): void {
    $user = User::factory()->unverified()->create(['email' => 'me@example.com']);

    $this->actingAs($user)->putJson('/api/v1/profile', [
        'name' => 'Me',
        'email' => 'other@example.com',
        'is_staff' => true,
        'email_verified_at' => now()->toIso8601String(),
        'current_tenant_id' => 1,
    ])->assertOk();

    $fresh = $user->fresh();
    expect($fresh?->email)->toBe('me@example.com')
        ->and($fresh?->is_staff)->toBeFalse()
        ->and($fresh?->hasVerifiedEmail())->toBeFalse()
        ->and($fresh?->current_tenant_id)->toBeNull();
});

it('changes the password only with the current one, and signs out the other sessions', function (): void {
    $user = User::factory()->create();
    DB::table('sessions')->insert(['id' => 'laptop', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()]);

    $this->actingAs($user)->putJson('/api/v1/profile/password', [
        'current_password' => 'wrong',
        'password' => 'a new password here',
        'password_confirmation' => 'a new password here',
    ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

    $this->actingAs($user)->putJson('/api/v1/profile/password', [
        'current_password' => 'password',
        'password' => 'a new password here',
        'password_confirmation' => 'a new password here',
    ])->assertNoContent();

    expect(Hash::check('a new password here', (string) $user->fresh()?->getAuthPassword()))->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'laptop')->exists())->toBeFalse();
});

it('lets a Google-only account set a first password, without a current one', function (): void {
    $user = User::factory()->googleOnly()->create();

    $this->actingAs($user)->putJson('/api/v1/profile/password', [
        'current_password' => 'anything',
        'password' => 'my first password',
        'password_confirmation' => 'my first password',
    ])->assertUnprocessable()->assertJsonValidationErrors('current_password');

    $this->actingAs($user)->putJson('/api/v1/profile/password', [
        'password' => 'my first password',
        'password_confirmation' => 'my first password',
    ])->assertNoContent();

    expect($user->fresh()?->hasPassword())->toBeTrue();
});

it('needs a session for every profile action', function (): void {
    $this->getJson('/api/v1/profile')->assertUnauthorized();
    $this->putJson('/api/v1/profile', ['name' => 'x'])->assertUnauthorized();
    $this->putJson('/api/v1/profile/password', [])->assertUnauthorized();
});
