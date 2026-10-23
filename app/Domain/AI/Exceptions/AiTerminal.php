<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use App\Domain\AI\Data\AiUsage;

/**
 * Another attempt with the same input would fail the same way: a truncated answer, a blocked or
 * recited one, malformed JSON, answers under the wrong ids (misaligned: the unit is split, never
 * guessed), a model without a confirmed price, a provider that is not configured.
 */
final class AiTerminal extends AiException
{
    public const string MISALIGNED = 'misaligned';

    public const string TRUNCATED = 'truncated';

    public const string UNPRICED_MODEL = 'unpriced_model';

    public const string PROMPT_TOO_LARGE = 'prompt_too_large';

    public const string NO_ROUTE = 'no_route';

    public const string PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public static function misaligned(AiUsage $usage): self
    {
        return new self(self::MISALIGNED, 'The answer ids do not match the item ids.', $usage);
    }

    public static function truncated(AiUsage $usage): self
    {
        return new self(self::TRUNCATED, 'The answer was cut off at the output token limit.', $usage);
    }
}
