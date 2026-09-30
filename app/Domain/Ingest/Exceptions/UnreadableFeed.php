<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Exceptions;

use RuntimeException;

/**
 * A feed that cannot be read as it is: the reason is typed, the message says what to change.
 * Never a partial success: an import that meets one of these stops, and nothing downstream sees
 * half a catalogue.
 */
final class UnreadableFeed extends RuntimeException
{
    public function __construct(
        public readonly UnreadableReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function because(UnreadableReason $reason, string $message): self
    {
        return new self($reason, $message);
    }
}
