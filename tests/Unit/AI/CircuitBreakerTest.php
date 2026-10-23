<?php

declare(strict_types=1);

use App\Domain\AI\AiPurpose;
use App\Domain\AI\Contracts\AiClient;
use App\Domain\AI\Data\AiItem;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiResponse;
use App\Domain\AI\Data\Prompt;
use App\Domain\AI\Exceptions\AiRetryable;
use App\Domain\AI\Gateway\CircuitBreaker;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

/** A store that hands numbers back as strings, as Redis does (RedisStore keeps numerics unserialized). */
function redisLikeCache(): Repository
{
    return new Repository(new class extends ArrayStore
    {
        public function get($key): mixed
        {
            $value = parent::get($key);

            return is_int($value) ? (string) $value : $value;
        }
    });
}

it('opens on a cache that returns numbers as strings, as Redis does on the servers', function (): void {
    $calls = 0;
    $failing = new class($calls) implements AiClient
    {
        public function __construct(public int &$calls) {}

        public function generate(AiRequest $request): AiResponse
        {
            $this->calls++;

            throw new AiRetryable('server_error', 'Upstream 503.');
        }
    };
    $breaker = new CircuitBreaker($failing, redisLikeCache(), threshold: 2, cooldownSeconds: 60);
    $request = (new AiRequest(1, AiPurpose::Translation, new Prompt('t', '1', 's', 'i'), [new AiItem('a', 'x')], [], 10, 1))
        ->routedTo('m', 'gemini');

    $reasons = [];
    foreach (range(1, 3) as $ignored) {
        try {
            $breaker->generate($request);
        } catch (AiRetryable $failure) {
            $reasons[] = $failure->reason;
        }
    }

    expect($reasons)->toBe(['server_error', 'server_error', AiRetryable::CIRCUIT_OPEN])->and($calls)->toBe(2);
});
