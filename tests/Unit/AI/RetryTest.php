<?php

declare(strict_types=1);

use App\Domain\AI\AiPurpose;
use App\Domain\AI\Contracts\AiClient;
use App\Domain\AI\Data\AiItem;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiResponse;
use App\Domain\AI\Data\AiUsage;
use App\Domain\AI\Data\Prompt;
use App\Domain\AI\Exceptions\AiRetryable;
use App\Domain\AI\Gateway\Retry;
use Illuminate\Support\Sleep;

/** An AiClient that fails $failures times, then answers. */
function flakyClient(int $failures): AiClient
{
    return new class($failures) implements AiClient
    {
        public function __construct(private int $failures) {}

        public function generate(AiRequest $request): AiResponse
        {
            if ($this->failures-- > 0) {
                throw new AiRetryable('server_error', 'Upstream 503.');
            }

            return new AiResponse([], new AiUsage, 'm', 'p', $request->attempt);
        }
    };
}

function retryRequest(): AiRequest
{
    return new AiRequest(1, AiPurpose::Translation, new Prompt('t', '1', 's', 'i'), [new AiItem('a', 'x')], [], 10, 1);
}

it('backs off exponentially from the base up to the cap, the jitter choosing within each ceiling', function (): void {
    Sleep::fake();
    $ceilings = [];
    $retry = new Retry(flakyClient(4), attempts: 5, baseMs: 1000, capMs: 5000, jitter: function (int $ceiling) use (&$ceilings): int {
        $ceilings[] = $ceiling;

        return $ceiling;
    });

    expect($retry->generate(retryRequest())->attempt)->toBe(5)
        ->and($ceilings)->toBe([1000, 2000, 4000, 5000]);
    Sleep::assertSequence([
        Sleep::for(1000)->milliseconds(),
        Sleep::for(2000)->milliseconds(),
        Sleep::for(4000)->milliseconds(),
        Sleep::for(5000)->milliseconds(),
    ]);
});

it('uses the jitter it is given, so delays spread across workers', function (): void {
    Sleep::fake();
    $retry = new Retry(flakyClient(2), attempts: 3, baseMs: 1000, capMs: 5000, jitter: static fn (int $ceiling): int => intdiv($ceiling, 4));

    $retry->generate(retryRequest());

    Sleep::assertSequence([Sleep::for(250)->milliseconds(), Sleep::for(500)->milliseconds()]);
});
