<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

/**
 * The gateway refused the call before it left: kill switch, frozen or paused workspace, unverified
 * owner, no reservation, a reservation too small for the call. The work is held, not failed; it
 * resumes when the cause clears (a top-up, a new period, an upgrade).
 */
final class AiDenied extends AiException
{
    public static function because(string $reason, string $message): self
    {
        return new self($reason, $message);
    }
}
