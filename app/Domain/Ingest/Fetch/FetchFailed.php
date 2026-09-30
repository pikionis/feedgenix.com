<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Fetch;

use RuntimeException;

/** A feed URL that could not be fetched; the message says what the customer can do about it. */
final class FetchFailed extends RuntimeException
{
    public function __construct(
        public readonly FetchFailure $reason,
        string $message,
        public readonly ?int $status = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    public static function because(FetchFailure $reason, string $message, ?int $status = null, ?int $retryAfterSeconds = null): self
    {
        return new self($reason, $message, $status, $retryAfterSeconds);
    }
}
