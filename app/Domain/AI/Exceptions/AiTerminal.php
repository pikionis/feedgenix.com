<?php

declare(strict_types=1);

namespace App\Domain\AI\Exceptions;

use App\Domain\AI\Data\AiUsage;
use App\Domain\AI\Data\BilledUsage;

/**
 * Another attempt with the same input would fail the same way: a truncated answer, a blocked or
 * recited one, malformed JSON, answers under the wrong ids (misaligned: the unit is split, never
 * guessed), a model without a confirmed price, a provider that is not configured.
 */
final class AiTerminal extends AiException
{
    public const string MISALIGNED = 'misaligned';

    public const string TRUNCATED = 'truncated';

    public const string MALFORMED = 'malformed';

    public const string INVALID_ANSWER = 'invalid_answer';

    public const string BLOCKED = 'blocked';

    public const string RECITATION = 'recitation';

    public const string REFUSED = 'refused';

    public const string UNPRICED_MODEL = 'unpriced_model';

    public const string PROMPT_TOO_LARGE = 'prompt_too_large';

    public const string NO_ROUTE = 'no_route';

    public const string PROVIDER_UNAVAILABLE = 'provider_unavailable';

    public const string PROVIDER_AUTH = 'provider_auth';

    public const string PROVIDER_BILLING = 'provider_billing';

    public const string BAD_REQUEST = 'bad_request';

    public const string UNKNOWN_MODEL = 'unknown_model';

    /**
     * @param  list<BilledUsage>  $billed  per-model usage when the attempt was billed on more than one model
     */
    public function __construct(
        string $reason,
        string $message,
        ?AiUsage $usage = null,
        public readonly array $billed = [],
    ) {
        parent::__construct($reason, $message, $usage);
    }

    public static function misaligned(AiUsage $usage): self
    {
        return new self(self::MISALIGNED, 'The answer ids do not match the item ids.', $usage);
    }

    public static function truncated(AiUsage $usage): self
    {
        return new self(self::TRUNCATED, 'The answer was cut off at the output token limit.', $usage);
    }

    public static function malformed(AiUsage $usage): self
    {
        return new self(self::MALFORMED, 'The answer is not the JSON shape that was asked for.', $usage);
    }

    public static function invalidAnswer(AiUsage $usage, string $id): self
    {
        return new self(self::INVALID_ANSWER, "The answer for item [{$id}] does not match its schema.", $usage);
    }
}
