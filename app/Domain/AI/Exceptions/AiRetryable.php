<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use App\Domain\AI\Data\AiUsage;

/**
 * Worth another attempt: a rate limit (429), an overloaded or failing provider (5xx), a timeout, a
 * provider skipped by its circuit breaker. $retryAfterSeconds is the provider's own Retry-After.
 */
final class AiRetryable extends AiException
{
    public const string RATE_LIMITED = 'rate_limited';

    public const string CIRCUIT_OPEN = 'circuit_open';

    public function __construct(
        string $reason,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
        ?AiUsage $usage = null,
    ) {
        parent::__construct($reason, $message, $usage);
    }

    public static function rateLimited(?int $retryAfterSeconds = null): self
    {
        return new self(self::RATE_LIMITED, 'The provider is rate limiting this account.', $retryAfterSeconds);
    }

    /** A provider failure that counts against its circuit breaker (every retryable reason but 429). */
    public function countsAgainstProvider(): bool
    {
        return $this->reason !== self::RATE_LIMITED && $this->reason !== self::CIRCUIT_OPEN;
    }
}
