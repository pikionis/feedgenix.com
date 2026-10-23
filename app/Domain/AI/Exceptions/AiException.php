<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use App\Domain\AI\Data\AiUsage;
use RuntimeException;

/**
 * A typed gateway failure. Callers decide by class and $code, never by the message (ADR 0004). When
 * the provider billed the attempt anyway (a truncated or misaligned answer), $usage says how much,
 * so the attempt is still recorded and charged.
 */
abstract class AiException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
        public readonly ?AiUsage $usage = null,
    ) {
        parent::__construct($message);
    }
}
