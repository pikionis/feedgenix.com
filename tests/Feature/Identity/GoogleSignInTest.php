<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\CreateInvite;
use App\Domain\Identity\Models\SignupInvite;
use App\Http\Controllers\Auth\GoogleController;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as GoogleUser;

/** @param  array<string, mixed>  $raw */
function fakeGoogle(string $id = 'g-123', ?string $email = 'ann@gmail.com', bool $verified = true, array $raw = []): void
{
    $google = (new GoogleUser)
        ->setRaw($raw + ['email_verified' => $verified])
        ->map(['id' => $id, 'email' => $email, 'name' => 'Ann Lee', 'avatar' => 'https://lh3.googleusercontent.com/a/ann']);

    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andReturn($google);
    $provider->shouldReceive('redirect')->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2/auth'));
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
}

function frontendUrl(string $path): string
{
    return config('feedgenix.frontend_url').$path;
}

beforeEach(fn () => config([
    'feedgenix.registration' => 'open',
    'services.google.client_id' => 'test-client-id',
    'services.google.client_secret' => 'test-client-secret',
]));

it('signs up a new person: verified, linked, no password, own workspace', function (): void {
    fakeGoogle();

    $this->get('/auth/google/callback')->assertRedirect(frontendUrl('/app'));

    $user = User::query()->where('email', 'ann@gmail.com')->firstOrFail();
    expect($user->google_id)->toBe('g-123')
        ->and($user->hasVerifiedEmail())->toBeTrue()
        ->and($user->hasPassword())->toBeFalse()
        ->and($user->roleIn((int) $user->current_tenant_id))->toBe('owner');
    $this->assertAuthenticatedAs($user);
});

it('signs in the linked account by its Google id, even after the address changed', function (): void {
    $user = User::factory()->create(['google_id' => 'g-123', 'email' => 'old@example.com']);
    fakeGoogle(email: 'new@example.com');

    $this->get('/auth/google/callback')->assertRedirect(frontendUrl('/app'));

    $this->assertAuthenticatedAs($user);
    expect(User::query()->count())->toBe(1);
});

it('links a verified password account and keeps its password', function (): void {
    $user = User::factory()->create(['email' => 'ann@gmail.com']);
    fakeGoogle();

    $this->get('/auth/google/callback')->assertRedirect(frontendUrl('/app'));

    $fresh = $user->fresh();
    expect($fresh?->google_id)->toBe('g-123')
        ->and(Auth::guard('web')->validate(['email' => 'ann@gmail.com', 'password' => 'password']))->toBeTrue();
});

it('takes back an unverified account someone registered with this address first (legacy D20)', function (): void {
    // A squatter registered ann@gmail.com with their own password and never verified it.
    $squatted = User::factory()->unverified()->create(['email' => 'ann@gmail.com']);
    DB::table('sessions')->insert(['id' => 'squatter-session', 'user_id' => $squatted->id, 'payload' => '', 'last_activity' => time()]);
    fakeGoogle();

    $this->get('/auth/google/callback')->assertRedirect(frontendUrl('/app'));

    $fresh = $squatted->fresh();
    expect($fresh?->hasPassword())->toBeFalse()
        ->and($fresh?->hasVerifiedEmail())->toBeTrue()
        ->and($fresh?->google_id)->toBe('g-123')
        ->and(DB::table('sessions')->where('id', 'squatter-session')->exists())->toBeFalse()
        ->and(Auth::guard('web')->validate(['email' => 'ann@gmail.com', 'password' => 'password']))->toBeFalse();
});

it('refuses an address Google has not verified', function (): void {
    fakeGoogle(verified: false);

    $this->get('/auth/google/callback')->assertRedirect(frontendUrl('/login?error=unverified_email'));

    expect(User::query()->count())->toBe(0);
    $this->assertGuest();
});

it('refuses an address whose account is linked to a different Google identity', function (): void {
    User::factory()->create(['email' => 'ann@gmail.com', 'google_id' => 'g-999']);
    fakeGoogle(id: 'g-123');

    $this->get('/auth/google/callback')->assertRedirect(frontendUrl('/login?error=conflict'));
    $this->assertGuest();
});

it('needs an invite for a new person in the private beta', function (): void {
    config(['feedgenix.registration' => 'invite']);
    fakeGoogle();

    $this->get('/auth/google/callback')->assertRedirect(frontendUrl('/register?error=invite'));
    expect(User::query()->count())->toBe(0);

    $code = resolve(CreateInvite::class)->handle();
    $this->withSession([GoogleController::INVITE_SESSION_KEY => $code])
        ->get('/auth/google/callback')->assertRedirect(frontendUrl('/app'));

    expect(SignupInvite::query()->sole()->used_by)->toBe(User::query()->where('email', 'ann@gmail.com')->value('id'));
});

it('keeps the invite the person arrived with for the callback', function (): void {
    fakeGoogle();

    $this->get('/auth/google/redirect?invite=fg-abc')
        ->assertRedirect('https://accounts.google.com/o/oauth2/auth')
        ->assertSessionHas(GoogleController::INVITE_SESSION_KEY, 'fg-abc');
});

it('refuses new people while registration is closed but still lets existing ones in', function (): void {
    config(['feedgenix.registration' => 'closed']);
    fakeGoogle();
    $this->get('/auth/google/callback')->assertRedirect(frontendUrl('/register?error=closed'));

    $existing = User::factory()->create(['google_id' => 'g-123']);
    $this->get('/auth/google/callback')->assertRedirect(frontendUrl('/app'));
    $this->assertAuthenticatedAs($existing);
});

it('sends the person back to sign-in when the Google exchange fails', function (): void {
    $provider = Mockery::mock(Provider::class);
    $provider->shouldReceive('user')->andThrow(new RuntimeException('invalid state'));
    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    $this->get('/auth/google/callback')->assertRedirect(frontendUrl('/login?error=google'));
});

// Staging had no Google credentials: Google answered "Missing required parameter: client_id".
describe('without Google credentials', function (): void {
    beforeEach(fn () => config(['services.google.client_id' => null, 'services.google.client_secret' => '']));

    it('tells the sign-in page that Google is not offered', function (): void {
        $this->getJson('/api/auth/providers')->assertOk()->assertExactJson(['google' => false]);
    });

    it('never sends the person to Google, and says why on the sign-in page', function (): void {
        Socialite::shouldReceive('driver')->never();

        $this->get('/auth/google/redirect')->assertRedirect(frontendUrl('/login?error=google_unavailable'));
    });

    it('keeps the invite: back to sign-up with the code and the reason', function (): void {
        Socialite::shouldReceive('driver')->never();

        $this->get('/auth/google/redirect?invite=fg-abc')
            ->assertRedirect(frontendUrl('/register?invite=fg-abc&error=google_unavailable'))
            ->assertSessionMissing(GoogleController::INVITE_SESSION_KEY);
    });

    it('needs both the id and the secret', function (): void {
        config(['services.google.client_id' => 'only-the-id']);

        $this->getJson('/api/auth/providers')->assertExactJson(['google' => false]);
    });
});

it('offers Google when the server has its credentials', function (): void {
    $this->getJson('/api/auth/providers')->assertOk()->assertExactJson(['google' => true]);
});
