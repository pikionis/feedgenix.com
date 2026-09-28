<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Identity\Actions\CreateInvite;
use App\Support\Frontend;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'invites:create', description: 'Create a private-beta invite code (the code is shown only once)')]
#[Signature('invites:create
        {--email= : Only this address may use the code}
        {--days= : Days until the code expires (default from config, max 365)}
        {--note= : Who the invite is for}')]
final class CreateInviteCommand extends Command
{
    public function handle(CreateInvite $createInvite): int
    {
        $email = $this->option('email');
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('--email must be a valid email address.');

            return self::INVALID;
        }

        $days = $this->option('days');
        if ($days !== null && (! ctype_digit($days) || (int) $days < 1 || (int) $days > 365)) {
            $this->error('--days must be a whole number between 1 and 365.');

            return self::INVALID;
        }

        $note = $this->option('note');

        $code = $createInvite->handle(
            email: $email,
            days: $days === null ? null : (int) $days,
            note: $note === null ? null : mb_substr($note, 0, 120),
        );

        $this->line("Invite code:  {$code}");
        $this->line('Sign-up link: '.Frontend::url('/register', ['invite' => $code]));
        $this->warn('The code is shown only now; only its hash is stored.');

        return self::SUCCESS;
    }
}
