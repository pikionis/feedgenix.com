<?php

declare(strict_types=1);

namespace App\Domain\Identity;

use InvalidArgumentException;

/** Who may create an account (config feedgenix.registration). */
enum RegistrationMode: string
{
    case Open = 'open';
    case Invite = 'invite';
    case Closed = 'closed';

    public static function current(): self
    {
        $value = config('feedgenix.registration');

        return (is_string($value) ? self::tryFrom($value) : null)
            ?? throw new InvalidArgumentException('feedgenix.registration must be "open", "invite" or "closed".');
    }
}
