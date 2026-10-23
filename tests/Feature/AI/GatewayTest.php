<?php

declare(strict_types=1);

use App\Domain\AI\AiPurpose;
use App\Domain\AI\Contracts\AiClient;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiResponse;
use App\Domain\AI\Data\AiUsage;
use App\Domain\AI\Data\ProviderResult;
use App\Domain\AI\Exceptions\AiDenied;
use App\Domain\AI\Exceptions\AiRetryable;
use App\Domain\AI\Exceptions\AiTerminal;
use App\Domain\AI\Models\EngineCall;
use App\Domain\AI\Providers\FakeAiProvider;
use App\Domain\Credits\Actions\SettleReservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
 * The real gateway chain with fake providers under it (ADR 0004): policy, reservations, recording,
 * charging and the answer-shape checks. Resilience (retry, breaker, fallback) is ResilienceTest.
 */

describe('policy', function (): void {
    it('refuses every call while the kill switch is off, before anything is spent', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        config(['ai.enabled' => false]);
        $reservation = reserveFor($tenant);

        $failure = aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)));

        expect($failure)->toBeInstanceOf(AiDenied::class)
            ->and($failure->reason)->toBe('ai_disabled')
            ->and($fakes['gemini']->calls)->toBe([])
            ->and(engineCalls($tenant))->toBe([]);
    });

    it('refuses a call for one workspace made in the context of another, of the system or of none', function (string $context): void {
        $tenant = aiWorkspace();
        $other = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant);
        $request = aiRequest($tenant, $reservation->id);
        $call = static fn () => resolve(AiClient::class)->generate($request);

        $failure = aiFailure(match ($context) {
            'other workspace' => static fn (): mixed => tenancy()->run($other->id, $call),
            'system' => static fn (): mixed => tenancy()->runAsSystem('test', $call),
            'none' => $call,
        });

        expect($failure->reason)->toBe('tenant_mismatch')->and($fakes['gemini']->calls)->toBe([]);
    })->with(['other workspace', 'system', 'none']);

    it('refuses a frozen workspace, a paused one and one whose owner is unverified', function (string $state): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant);
        match ($state) {
            'workspace_frozen' => $tenant->forceFill(['frozen_at' => now()])->save(),
            'ai_paused' => $tenant->forceFill(['ai_paused_at' => now(), 'ai_paused_reason' => 'abuse'])->save(),
            'owner_unverified' => User::query()->whereKey($tenant->owner_id)->update(['email_verified_at' => null]),
        };

        $failure = aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)));

        expect($failure)->toBeInstanceOf(AiDenied::class)
            ->and($failure->reason)->toBe($state)
            ->and($fakes['gemini']->calls)->toBe([]);
    })->with(['workspace_frozen', 'ai_paused', 'owner_unverified']);
});

describe('reservations', function (): void {
    it('refuses a call without a reservation (no reservation, no call)', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();

        $failure = aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, null)));

        expect($failure->reason)->toBe('no_reservation')->and($fakes['gemini']->calls)->toBe([]);
    });

    it("never spends another workspace's reservation", function (): void {
        $tenant = aiWorkspace();
        $victim = aiWorkspace();
        $fakes = fakeAi();
        reserveFor($tenant);
        $theirs = reserveFor($victim);

        $failure = aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $theirs->id)));

        expect($failure->reason)->toBe('no_reservation')
            ->and($fakes['gemini']->calls)->toBe([])
            ->and(reservationOf($victim, $theirs->id)->consumed)->toBe(0);
    });

    it('refuses a settled or expired reservation', function (string $how): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant);
        if ($how === 'settled') {
            tenancy()->run($tenant->id, fn (): bool => resolve(SettleReservation::class)->handle($reservation->id));
        } else {
            $this->travel(61)->minutes();
        }

        $failure = aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)));

        expect($failure->reason)->toBe('reservation_closed')->and($fakes['gemini']->calls)->toBe([]);
    })->with(['settled', 'expired']);

    it('refuses a call whose worst case the reservation cannot cover, before it leaves', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        // 1,000 output tokens on the test flash price alone cost more than 2,000 millicredits.
        $reservation = reserveFor($tenant, 2_000);

        $failure = aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)));

        expect($failure->reason)->toBe('reservation_exhausted')
            ->and($fakes['gemini']->calls)->toBe([])
            ->and(engineCalls($tenant))->toBe([]);
    });

    it('refuses up front when the reservation could not cover the fallback, so no work is left half done', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        // Enough for this call's worst case on the first mapping model, not on its costlier fallback.
        $reservation = reserveFor($tenant, 150_000);

        $failure = aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id, AiPurpose::Mapping)));

        expect($failure->reason)->toBe('reservation_exhausted')
            ->and($fakes['gemini']->calls)->toBe([])
            ->and($fakes['anthropic']->calls)->toBe([]);
    });

    it('stops a second call once the first has consumed what the reservation held', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant, 40_000);
        $fakes['gemini']->push(fn (AiRequest $request): ProviderResult => FakeAiProvider::echo($request, new AiUsage(inputTokens: 2_000, outputTokens: 1_000)));

        generateAs($tenant, aiRequest($tenant, $reservation->id));
        $failure = aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)));

        expect($failure->reason)->toBe('reservation_exhausted')->and($fakes['gemini']->calls)->toHaveCount(1);
    });
});

describe('charging', function (): void {
    it('charges exactly provider cost × 1.1, thinking tokens included, and records the attempt', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant);
        $fakes['gemini']->push(fn (AiRequest $request): ProviderResult => FakeAiProvider::echo($request, new AiUsage(inputTokens: 1_000, outputTokens: 500, thinkingTokens: 200)));

        $response = generateAs($tenant, aiRequest($tenant, $reservation->id));

        // (1,000 × 0.30 + (500 + 200) × 2.50) / 1M = $0.00205 → × 0.92 × 1.1 = €0.0020746 = 20.746 credits.
        expect($response->millicredits)->toBe(20_746)
            ->and($response->values)->toBe(['p-1' => ['text' => 'Wanderschuh aus Leder'], 'p-2' => ['text' => 'Regenjacke']])
            ->and($response->provider)->toBe('gemini')
            ->and($response->model)->toBe('gemini-2.5-flash')
            ->and(reservationOf($tenant, $reservation->id)->consumed)->toBe(20_746);

        $calls = engineCalls($tenant);
        expect($calls)->toHaveCount(1)
            ->and($calls[0]->only(['purpose', 'provider', 'model', 'prompt', 'attempt', 'item_count', 'input_tokens', 'output_tokens', 'thinking_tokens', 'status', 'error_code', 'millicredits', 'unbilled_millicredits']))
            ->toBe([
                'purpose' => 'translation', 'provider' => 'gemini', 'model' => 'gemini-2.5-flash', 'prompt' => 'translate@1',
                'attempt' => 1, 'item_count' => 2, 'input_tokens' => 1_000, 'output_tokens' => 500, 'thinking_tokens' => 200,
                'status' => 'ok', 'error_code' => null, 'millicredits' => 20_746, 'unbilled_millicredits' => 0,
            ])
            ->and($calls[0]->cost_usd)->toBe('0.0020500000')
            ->and($calls[0]->toArray())->not->toHaveKey('cost_usd')
            ->and($calls[0]->reservation_id)->toBe($reservation->id);
    });

    it('prices cached input at its own rate and rounds up to a whole millicredit', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant);
        $fakes['gemini']->push(fn (AiRequest $request): ProviderResult => FakeAiProvider::echo($request, new AiUsage(inputTokens: 1_000, outputTokens: 500, thinkingTokens: 200, cachedInputTokens: 400)));

        // + 400 × 0.075 = $0.00208 → €0.00210496 = 21.0496 credits → 21,049.6 millicredits → 21,050.
        expect(generateAs($tenant, aiRequest($tenant, $reservation->id))->millicredits)->toBe(21_050);
    });

    it('refuses a model without a confirmed price, before anything leaves', function (string $missing): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        setModel('gemini-2.5-flash', $missing, null);
        $reservation = reserveFor($tenant);

        $failure = aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)));

        expect($failure)->toBeInstanceOf(AiTerminal::class)
            ->and($failure->reason)->toBe(AiTerminal::UNPRICED_MODEL)
            ->and($fakes['gemini']->calls)->toBe([]);
    })->with(['input', 'output', 'cached_input']);

    it('skips an unpriced model on a route and runs on the next priced one', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        setModel('gemini-2.5-pro', 'output', null);
        $reservation = reserveFor($tenant, 5_000_000);

        $response = generateAs($tenant, aiRequest($tenant, $reservation->id, AiPurpose::Mapping));

        expect($response->model)->toBe('claude-opus-5-5')->and($fakes['gemini']->calls)->toBe([]);
    });

    it('refuses a prompt that could pass the size its flat price holds for', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        setModel('gemini-2.5-flash', 'max_input_tokens', 3_000);
        $reservation = reserveFor($tenant);

        $failure = aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id, items: ['p-1' => str_repeat('Leder ', 200)])));

        expect($failure->reason)->toBe(AiTerminal::PROMPT_TOO_LARGE)->and($fakes['gemini']->calls)->toBe([]);
    });

    it('never charges past the reservation: an over-reporting attempt is capped and the rest logged as unbilled', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant, 40_000);
        Log::spy();
        $fakes['gemini']->push(fn (AiRequest $request): ProviderResult => FakeAiProvider::echo($request, new AiUsage(inputTokens: 1_000_000, outputTokens: 1_000_000)));

        $response = generateAs($tenant, aiRequest($tenant, $reservation->id));

        $call = engineCalls($tenant)[0];
        expect($response->millicredits)->toBe(40_000)
            ->and($call->millicredits)->toBe(40_000)
            ->and($call->unbilled_millicredits)->toBeGreaterThan(0)
            ->and(reservationOf($tenant, $reservation->id)->consumed)->toBe(40_000);
        Log::shouldHaveReceived('warning')->once();
    });

    it('refuses to run inside a database transaction, whose rollback would erase a billed charge', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant);

        expect(fn () => DB::transaction(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id))))
            ->toThrow(LogicException::class, 'must not run inside a database transaction');
        expect($fakes['gemini']->calls)->toBe([]);
    });
});

describe('answers', function (): void {
    it('treats renumbered, missing or extra ids as misaligned: billed, recorded, never retried or guessed', function (array $returned): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant);
        $fakes['gemini']->push(new ProviderResult($returned, new AiUsage(inputTokens: 1_000, outputTokens: 100), 'gemini-2.5-flash'));

        $failure = aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)));

        $calls = engineCalls($tenant);
        expect($failure)->toBeInstanceOf(AiTerminal::class)
            ->and($failure->reason)->toBe(AiTerminal::MISALIGNED)
            ->and($fakes['gemini']->calls)->toHaveCount(1)
            ->and($calls)->toHaveCount(1)
            ->and($calls[0]->status)->toBe('terminal')
            ->and($calls[0]->error_code)->toBe('misaligned')
            ->and($calls[0]->millicredits)->toBeGreaterThan(0);
    })->with([
        'renumbered' => [['1' => ['text' => 'a'], '2' => ['text' => 'b']]],
        'missing' => [['p-1' => ['text' => 'a']]],
        'extra' => [['p-1' => ['text' => 'a'], 'p-2' => ['text' => 'b'], 'p-3' => ['text' => 'c']]],
    ]);

    it('accepts numeric ids, which PHP turns into integer array keys', function (): void {
        $tenant = aiWorkspace();
        fakeAi();
        $reservation = reserveFor($tenant);

        $response = generateAs($tenant, aiRequest($tenant, $reservation->id, items: ['17' => 'Zelt', '4' => 'Schlafsack']));

        expect(array_map(strval(...), array_keys($response->values)))->toBe(['17', '4']);
    });

    it('treats a cut-off answer as terminal and still charges what the provider billed', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant);
        $fakes['gemini']->push(fn (AiRequest $request): ProviderResult => new ProviderResult(FakeAiProvider::echo($request)->values, new AiUsage(inputTokens: 500, outputTokens: 1_000), 'gemini-2.5-flash', truncated: true));

        $failure = aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)));

        expect($failure->reason)->toBe(AiTerminal::TRUNCATED)
            ->and(engineCalls($tenant)[0]->millicredits)->toBeGreaterThan(0)
            ->and($fakes['gemini']->calls)->toHaveCount(1);
    });

    it('records every attempt, the failed ones too', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant);
        $fakes['gemini']->push(new AiRetryable('server_error', 'Upstream 503.'));

        generateAs($tenant, aiRequest($tenant, $reservation->id));

        $calls = engineCalls($tenant);
        expect(array_map(static fn (EngineCall $call): array => [$call->attempt, $call->status, $call->error_code], $calls))
            ->toBe([[1, 'retryable', 'server_error'], [2, 'ok', null]])
            ->and($calls[0]->millicredits)->toBe(0);
    });

    it('stops retrying once a billed failure leaves the reservation unable to cover another attempt', function (): void {
        $tenant = aiWorkspace();
        $fakes = fakeAi();
        $reservation = reserveFor($tenant, 40_000);
        $fakes['gemini']->push(new AiRetryable('server_error', 'Billed, then 503.', null, new AiUsage(inputTokens: 2_000, outputTokens: 1_000)));

        $failure = aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)));

        expect($failure)->toBeInstanceOf(AiDenied::class)
            ->and($failure->reason)->toBe('reservation_exhausted')
            ->and($fakes['gemini']->calls)->toHaveCount(1);
    });
});

it('validates the shape of a request', function (): void {
    $tenant = aiWorkspace();

    expect(fn (): AiRequest => aiRequest($tenant, 1, items: []))->toThrow(InvalidArgumentException::class, 'at least one item')
        ->and(fn (): AiRequest => aiRequest($tenant, 1, maxOutputTokens: 0))->toThrow(InvalidArgumentException::class, 'maxOutputTokens')
        ->and(AiPurpose::Translation->allowsFallback())->toBeFalse()
        ->and(AiPurpose::Mapping->allowsFallback())->toBeTrue();
});
