<?php

declare(strict_types=1);

namespace App\Domain\AI\Gateway;

use App\Domain\AI\Contracts\AiClient;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiResponse;
use App\Domain\AI\Exceptions\AiRetryable;
use Closure;
use Illuminate\Support\Sleep;

/**
 * Retries a retryable failure on the same model (legacy D13: 429s were never retried). The wait is
 * the provider's Retry-After when it sends one, otherwise full jitter: a random delay up to
 * min(cap, base × 2^(attempt − 1)). A Retry-After beyond the cap is not waited out here: the failure
 * goes back up, and the pipeline reschedules the unit.
 */
final readonly class Retry implements AiClient
{
    /** @param Closure(int): int $jitter  a delay between 0 and the given ceiling, in ms */
    public function __construct(
        private AiClient $next,
        private int $attempts,
        private int $baseMs,
        private int $capMs,
        private Closure $jitter,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        $attempt = 1;
        while (true) {
            try {
                return $this->next->generate($request->withAttempt($attempt));
            } catch (AiRetryable $failure) {
                $delay = $attempt < $this->attempts ? $this->delayMs($failure, $attempt) : null;
                if ($delay === null) {
                    throw $failure;
                }
                Sleep::for($delay)->milliseconds();
                $attempt++;
            }
        }
    }

    private function delayMs(AiRetryable $failure, int $attempt): ?int
    {
        if ($failure->retryAfterSeconds !== null) {
            $delay = max(0, $failure->retryAfterSeconds) * 1000;

            return $delay <= $this->capMs ? $delay : null;
        }

        return ($this->jitter)(min($this->capMs, $this->baseMs * 2 ** ($attempt - 1)));
    }
}
