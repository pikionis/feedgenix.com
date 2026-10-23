<?php

declare(strict_types=1);

namespace App\Domain\AI\Gateway;

use App\Domain\AI\Contracts\AiClient;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiResponse;
use App\Domain\AI\Exceptions\AiRetryable;
use Illuminate\Contracts\Cache\Repository;
use LogicException;

/**
 * Skips a provider after $threshold consecutive failed calls, for $cooldownSeconds, across every
 * worker (the shared cache). Rate limits (429) never trip it: they are the provider working as
 * designed and are handled by Retry. After the cooldown one more failure re-opens it at once; a
 * success closes it.
 */
final readonly class CircuitBreaker implements AiClient
{
    public function __construct(
        private AiClient $next,
        private Repository $cache,
        private int $threshold,
        private int $cooldownSeconds,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        $provider = $request->provider ?? throw new LogicException('The router names the provider before the breaker.');

        // Redis hands numbers back as strings, so read the timestamp as any numeric value.
        $openUntil = $this->cache->get($this->key($provider, 'open_until'));
        $now = now()->getTimestamp();
        if (is_numeric($openUntil) && (int) $openUntil > $now) {
            throw new AiRetryable(AiRetryable::CIRCUIT_OPEN, "Provider [{$provider}] is cooling down.", (int) $openUntil - $now);
        }

        try {
            $response = $this->next->generate($request);
        } catch (AiRetryable $failure) {
            if ($failure->countsAgainstProvider()) {
                $this->recordFailure($provider);
            }

            throw $failure;
        }

        $this->cache->forget($this->key($provider, 'failures'));

        return $response;
    }

    private function recordFailure(string $provider): void
    {
        $key = $this->key($provider, 'failures');
        $this->cache->add($key, 0, $this->cooldownSeconds * 10);
        $failures = (int) $this->cache->increment($key);

        if ($failures >= $this->threshold) {
            $this->cache->put($this->key($provider, 'open_until'), now()->getTimestamp() + $this->cooldownSeconds, $this->cooldownSeconds);
        }
    }

    private function key(string $provider, string $what): string
    {
        return "ai:breaker:{$provider}:{$what}";
    }
}
