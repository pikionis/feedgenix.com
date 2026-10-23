<?php

declare(strict_types=1);

use App\Domain\AI\AiPurpose;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiResponse;
use App\Domain\AI\Data\ProviderResult;
use App\Domain\AI\Exceptions\AiRetryable;
use App\Domain\AI\Exceptions\AiTerminal;
use App\Domain\AI\Models\EngineCall;
use App\Domain\AI\Providers\FakeAiProvider;
use App\Domain\AI\Providers\Providers;
use Carbon\CarbonInterval;
use Illuminate\Support\Sleep;

/*
 * Retry, circuit breaker and routing through the real gateway (ADR 0004). Legacy D13: 429s were never
 * retried. Every sleep is faked.
 */

function failing(int $times, string $reason = 'server_error'): array
{
    return array_map(static fn (): AiRetryable => new AiRetryable($reason, "Upstream {$reason}."), range(1, $times));
}

describe('retry', function (): void {
    it('retries a 429 after the Retry-After the provider asked for', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant);
        $fakes['gemini']->push(AiRetryable::rateLimited(2));

        $response = generateAs($tenant, aiRequest($tenant, $reservation->id));

        expect($response->attempt)->toBe(2)->and($fakes['gemini']->calls)->toHaveCount(2);
        Sleep::assertSequence([Sleep::for(2000)->milliseconds()]);
    });

    it('backs off with jitter that never exceeds base × 2^(attempt − 1) or the cap', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        config(['ai.retry.cap_ms' => 1_500]);
        $reservation = reserveFor($tenant);
        $fakes['gemini']->push(...failing(3));

        generateAs($tenant, aiRequest($tenant, $reservation->id));

        Sleep::assertSleptTimes(3);
        Sleep::assertSlept(static fn (CarbonInterval $slept): bool => $slept->totalMilliseconds <= 1_500, 3);
    });

    it('does not wait out a Retry-After beyond the cap: the unit goes back to the pipeline', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant);
        $fakes['gemini']->push(AiRetryable::rateLimited(3600));

        $failure = aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)));

        expect($failure)->toBeInstanceOf(AiRetryable::class)
            ->and($failure)->toHaveProperty('retryAfterSeconds', 3600)
            ->and($fakes['gemini']->calls)->toHaveCount(1);
        Sleep::assertNeverSlept();
    });

    it('gives up after the configured attempts and returns the last failure', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant);
        $fakes['gemini']->push(...failing(4));

        $failure = aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)));

        expect($failure)->toBeInstanceOf(AiRetryable::class)
            ->and($fakes['gemini']->calls)->toHaveCount(4)
            ->and(array_map(static fn (AiRequest $request): int => $request->attempt, $fakes['gemini']->calls))->toBe([1, 2, 3, 4])
            ->and(engineCalls($tenant))->toHaveCount(4);
        Sleep::assertSleptTimes(3);
    });

    it('never retries a terminal failure', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant);
        $fakes['gemini']->push(new AiTerminal('blocked', 'The provider blocked the answer.'));

        expect(aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)))->reason)->toBe('blocked')
            ->and($fakes['gemini']->calls)->toHaveCount(1);
        Sleep::assertNeverSlept();
    });
});

describe('circuit breaker', function (): void {
    beforeEach(function (): void {
        config(['ai.retry.attempts' => 1, 'ai.breaker.threshold' => 3, 'ai.breaker.cooldown_seconds' => 60]);
    });

    it('skips a provider after consecutive failures, without calling it', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant);
        $fakes['gemini']->push(...failing(3));

        foreach (range(1, 3) as $ignored) {
            aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)));
        }
        $failure = aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)));

        expect($failure->reason)->toBe(AiRetryable::CIRCUIT_OPEN)->and($fakes['gemini']->calls)->toHaveCount(3);
    });

    it('is never tripped by rate limits', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant);
        $fakes['gemini']->push(...array_map(static fn (): AiRetryable => AiRetryable::rateLimited(), range(1, 5)));

        foreach (range(1, 5) as $ignored) {
            aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)));
        }
        generateAs($tenant, aiRequest($tenant, $reservation->id));

        expect($fakes['gemini']->calls)->toHaveCount(6);
    });

    it('tries again after the cooldown, and one more failure re-opens it at once', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant);
        $fakes['gemini']->push(...failing(4));
        foreach (range(1, 3) as $ignored) {
            aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)));
        }

        $this->travel(61)->seconds();
        expect(aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)))->reason)->toBe('server_error')
            ->and(aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)))->reason)->toBe(AiRetryable::CIRCUIT_OPEN)
            ->and($fakes['gemini']->calls)->toHaveCount(4);
    });

    it('counts only consecutive failures: a success closes it', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant, 5_000_000);
        $fakes['gemini']->push(...failing(2));
        $fakes['gemini']->push(static fn (AiRequest $request): ProviderResult => FakeAiProvider::echo($request));
        $fakes['gemini']->push(...failing(2));

        foreach (range(1, 5) as $ignored) {
            rescue(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)), report: false);
        }
        generateAs($tenant, aiRequest($tenant, $reservation->id));

        expect($fakes['gemini']->calls)->toHaveCount(6);
    });
});

describe('routing', function (): void {
    it('falls back to the next model for a reasoning purpose', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant, 5_000_000);
        $fakes['gemini']->push(...failing(4));

        $response = generateAs($tenant, aiRequest($tenant, $reservation->id, AiPurpose::Mapping));

        expect($response->provider)->toBe('anthropic')
            ->and($response->model)->toBe('claude-sonnet-5-5')
            ->and($fakes['gemini']->calls)->toHaveCount(4)
            ->and($fakes['anthropic']->calls)->toHaveCount(1)
            ->and(array_map(static fn (EngineCall $call): string => $call->model, engineCalls($tenant)))
            ->toBe(['gemini-2.5-pro', 'gemini-2.5-pro', 'gemini-2.5-pro', 'gemini-2.5-pro', 'claude-sonnet-5-5']);
    });

    it('never switches models for translation, even when its route lists another', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        config(['ai.routes.translation' => ['gemini-2.5-flash', 'claude-sonnet-5-5']]);
        $reservation = reserveFor($tenant, 5_000_000);
        $fakes['gemini']->push(...failing(4));

        $failure = aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)));

        expect($failure)->toBeInstanceOf(AiRetryable::class)->and($fakes['anthropic']->calls)->toBe([]);
    });

    it('goes straight to the fallback while the first provider is cooling down', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        config(['ai.retry.attempts' => 1, 'ai.breaker.threshold' => 1]);
        $reservation = reserveFor($tenant, 5_000_000);
        $fakes['gemini']->push(...failing(1));
        generateAs($tenant, aiRequest($tenant, $reservation->id, AiPurpose::Mapping));

        $response = generateAs($tenant, aiRequest($tenant, $reservation->id, AiPurpose::Mapping));

        expect($response->provider)->toBe('anthropic')->and($fakes['gemini']->calls)->toHaveCount(1);
    });

    it('skips models whose provider is not configured, and refuses when none is', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi(['anthropic']);
        $reservation = reserveFor($tenant, 5_000_000);

        expect(generateAs($tenant, aiRequest($tenant, $reservation->id, AiPurpose::Mapping))->provider)->toBe('anthropic')
            ->and(aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)))->reason)->toBe(AiTerminal::PROVIDER_UNAVAILABLE);
    });

    it('runs on the requested model when one is named', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant, 5_000_000);

        $response = generateAs($tenant, aiRequest($tenant, $reservation->id, model: 'gemini-2.5-pro'));

        expect($response->model)->toBe('gemini-2.5-pro')->and($fakes['gemini']->calls[0]->model)->toBe('gemini-2.5-pro');
    });

    it('reaches no provider at all outside tests: no host is wired before step 2, and the fake is refused in production', function (): void {
        expect((new Providers([]))->has('gemini'))->toBeFalse()
            ->and(fn (): Providers => Providers::fromConfig(fake: true, production: true))->toThrow(RuntimeException::class, 'refused in production')
            ->and(Providers::fromConfig(fake: true, production: false)->has('gemini'))->toBeTrue()
            ->and(Providers::fromConfig(fake: false, production: false)->has('gemini'))->toBeFalse();
    });
});
