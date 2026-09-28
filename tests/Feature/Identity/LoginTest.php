<?php

declare(strict_types=1);

use App\Models\User;

it('signs in with the right password', function (): void {
    $user = User::factory()->withWorkspace()->create(['email' => 'log@example.com']);

    $this->postJson('/api/auth/login', ['email' => 'LOG@example.com', 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.workspace.role', 'owner');

    $this->assertAuthenticatedAs($user);
});

it('gives one identical answer for a wrong password, an unknown address and a Google-only account', function (): void {
    User::factory()->create(['email' => 'real@example.com']);
    User::factory()->googleOnly()->create(['email' => 'google@example.com']);

    $bodies = collect([
        ['email' => 'real@example.com', 'password' => 'wrong-password'],
        ['email' => 'nobody@example.com', 'password' => 'wrong-password'],
        ['email' => 'google@example.com', 'password' => 'wrong-password'],
    ])->map(fn (array $credentials): string => (string) $this->postJson('/api/auth/login', $credentials)
        ->assertUnprocessable()
        ->getContent());

    expect($bodies->unique()->count())->toBe(1);
    $this->assertGuest();
});

it('signs out', function (): void {
    $this->actingAs(User::factory()->create())->postJson('/api/v1/auth/logout')->assertNoContent();

    $this->assertGuest('web');
});

it('needs a session for the account endpoints', function (): void {
    $this->getJson('/api/v1/auth/user')->assertUnauthorized();
    $this->postJson('/api/v1/auth/logout')->assertUnauthorized();
});

it('locks an address out after five wrong passwords, even for the right one', function (): void {
    User::factory()->create(['email' => 'target@example.com']);

    foreach (range(1, 5) as $attempt) {
        $this->postJson('/api/auth/login', ['email' => 'target@example.com', 'password' => 'guess-'.$attempt])->assertUnprocessable();
    }

    $this->postJson('/api/auth/login', ['email' => 'target@example.com', 'password' => 'password'])->assertTooManyRequests();
    $this->assertGuest();
});
