<?php

declare(strict_types=1);

namespace App\Domain\AI\Gateway;

use App\Domain\AI\Contracts\AiClient;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiResponse;
use App\Domain\AI\Exceptions\AiRetryable;
use App\Domain\AI\Routes;

/**
 * Runs the call on its route's models in order. Only a retryable failure (rate limit, outage, open
 * breaker) moves on to the next model, and only for purposes that allow a fallback: translation never
 * switches models mid-catalogue (ADR 0004). A terminal answer is the caller's to handle.
 */
final readonly class Router implements AiClient
{
    public function __construct(
        private AiClient $next,
        private Routes $routes,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        $failure = null;
        foreach ($this->routes->candidates($request) as $candidate) {
            try {
                return $this->next->generate($request->routedTo($candidate['model'], $candidate['provider']));
            } catch (AiRetryable $retryable) {
                $failure = $retryable;
                if (! $request->purpose->allowsFallback()) {
                    break;
                }
            }
        }

        // Routes::candidates() is never empty, so the loop either returned or recorded a failure.
        throw $failure;
    }
}
