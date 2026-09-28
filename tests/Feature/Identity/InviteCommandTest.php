<?php

declare(strict_types=1);

use App\Domain\Identity\Models\SignupInvite;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Output\BufferedOutput;

it('creates a code that is shown once and stored only as a hash', function (): void {
    // An explicit buffer: when this is the first test touching the database, the lazy migration
    // runs inside the command and would replace Artisan::output() with its own.
    $buffer = new BufferedOutput;
    $exit = Artisan::call('invites:create', ['--email' => 'guest@example.com', '--days' => '7', '--note' => 'Beta shop #1'], $buffer);
    $output = $buffer->fetch();

    expect($exit)->toBe(0);
    preg_match('/Invite code:\s+(fg-[a-z0-9]{24})/', $output, $match);
    expect($match)->toHaveCount(2);

    $invite = SignupInvite::query()->sole();
    expect($invite->code_hash)->toBe(SignupInvite::hash($match[1]))
        ->and($invite->code_hash)->not->toContain($match[1])
        ->and($invite->email)->toBe('guest@example.com')
        ->and($invite->note)->toBe('Beta shop #1')
        ->and($invite->expires_at->isSameDay(now()->addDays(7)))->toBeTrue()
        ->and($output)->toContain('/register?invite='.$match[1]);
});

it('rejects a bad address or number of days', function (array $options): void {
    expect(Artisan::call('invites:create', $options))->toBe(2);
    expect(SignupInvite::query()->count())->toBe(0);
})->with([
    'bad email' => [['--email' => 'not-an-email']],
    'zero days' => [['--days' => '0']],
    'too many days' => [['--days' => '366']],
    'not a number' => [['--days' => '7d']],
]);
