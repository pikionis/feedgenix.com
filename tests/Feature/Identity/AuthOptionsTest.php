<?php

declare(strict_types=1);

/*
 * The sign-up page asks the server who may sign up: the invite field appears only during an
 * invite-only beta, and a closed sign-up says so before anyone fills in the form.
 */

it('tells the sign-up page who may sign up', function (string $mode): void {
    config(['feedgenix.registration' => $mode]);

    $this->getJson('/api/auth/options')->assertOk()->assertJsonPath('registration', $mode);
})->with(['open', 'invite', 'closed']);

it('answers with exactly the two options and never a credential', function (): void {
    config([
        'feedgenix.registration' => 'invite',
        'services.google.client_id' => 'id-that-must-stay-secret',
        'services.google.client_secret' => 'secret-that-must-stay-secret',
    ]);

    $response = $this->getJson('/api/auth/options')->assertOk()->assertExactJson(['google' => true, 'registration' => 'invite']);

    expect((string) $response->getContent())->not->toContain('must-stay-secret');
});

it('needs no session and starts none', function (): void {
    $this->getJson('/api/auth/options')->assertOk()->assertCookieMissing((string) config('session.cookie'));
});
