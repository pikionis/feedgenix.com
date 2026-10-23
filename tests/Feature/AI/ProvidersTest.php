<?php

declare(strict_types=1);

use App\Domain\AI\AiPurpose;
use App\Domain\AI\Data\AiResponse;
use App\Domain\AI\Data\AiUsage;
use App\Domain\AI\Data\ProviderResult;
use App\Domain\AI\Exceptions\AiTerminal;
use App\Domain\AI\Pricing;
use App\Domain\AI\Providers\ClaudeProvider;
use App\Domain\AI\Providers\GeminiProvider;
use App\Domain\AI\Providers\Providers;
use Illuminate\Support\Facades\Http;

/*
 * The real providers under the real gateway, the network faked. And the egress guard: a model host
 * is reachable only through a provider (ADR 0004).
 */

function useRealProviders(): void
{
    config([
        'ai.providers.gemini.key' => 'test-gemini-key',
        'ai.providers.anthropic.key' => 'test-anthropic-key',
    ]);
    app()->forgetScopedInstances();
    app()->forgetInstance(Providers::class);
}

it('runs a call through Gemini end to end: charged from its own usage report and recorded', function (): void {
    $tenant = aiWorkspace();
    fakeAi();
    useRealProviders();
    $reservation = reserveFor($tenant);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => json_encode(['items' => [
            ['id' => 'p-1', 'value' => ['text' => 'Leather hiking shoe']],
            ['id' => 'p-2', 'value' => ['text' => 'Rain jacket']],
        ]])]]], 'finishReason' => 'STOP']],
        'usageMetadata' => ['promptTokenCount' => 1000, 'candidatesTokenCount' => 500, 'thoughtsTokenCount' => 200],
    ])]);

    $response = generateAs($tenant, aiRequest($tenant, $reservation->id));

    expect($response->values['p-1'])->toBe(['text' => 'Leather hiking shoe'])
        ->and($response->millicredits)->toBe(20_746)
        ->and(engineCalls($tenant)[0]->only(['provider', 'model', 'thinking_tokens', 'status']))
        ->toBe(['provider' => 'gemini', 'model' => 'gemini-2.5-flash', 'thinking_tokens' => 200, 'status' => 'ok']);
});

it('bills a Claude refusal fallback on each model it ran on, at each model\'s own price', function (): void {
    $tenant = aiWorkspace();
    fakeAi();
    setModel('claude-opus-5-5', 'refusal_fallbacks', ['claude-opus-5']);
    setModel('claude-opus-5', 'input', '5');
    setModel('claude-opus-5', 'output', '25');
    setModel('claude-opus-5', 'cached_input', '0.50');
    setModel('claude-opus-5', 'priced_on', 'test');
    useRealProviders();
    $reservation = reserveFor($tenant, 5_000_000);
    Http::fake(['api.anthropic.com/*' => Http::response([
        'id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5',
        'content' => [['type' => 'text', 'text' => json_encode(['items' => [['id' => 'p-1', 'value' => ['text' => 'a']], ['id' => 'p-2', 'value' => ['text' => 'b']]]])]],
        'stop_reason' => 'end_turn', 'stop_sequence' => null,
        'usage' => [
            'input_tokens' => 1000, 'output_tokens' => 600, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0,
            'iterations' => [
                ['type' => 'message', 'model' => 'claude-opus-5-5', 'input_tokens' => 1000, 'output_tokens' => 20, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
                ['type' => 'fallback_message', 'model' => 'claude-opus-5', 'input_tokens' => 1000, 'output_tokens' => 600, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
            ],
        ],
    ])]);

    $response = generateAs($tenant, aiRequest($tenant, $reservation->id, AiPurpose::Mapping, model: 'claude-opus-5-5'));

    // Test prices: opus-5-5 (1,000 × 3 + 20 × 15) = 3,300; opus-5 (1,000 × 5 + 600 × 25) = 20,000.
    // $0.0233 × 0.92 × 1.1 = €0.0235796 = 235.796 credits.
    expect($response->millicredits)->toBe(235_796);
});

it('reserves for the fallback hop too, before anything leaves', function (): void {
    $tenant = aiWorkspace();
    fakeAi();
    setModel('claude-opus-5-5', 'refusal_fallbacks', ['claude-opus-5']);
    foreach (['input' => '5', 'output' => '25', 'cached_input' => '0.50', 'priced_on' => 'test'] as $key => $value) {
        setModel('claude-opus-5', $key, $value);
    }
    useRealProviders();
    // Covers one hop on claude-opus-5-5 (≈ 222,000), not both hops.
    $reservation = reserveFor($tenant, 300_000);
    Http::fake();

    $failure = aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id, AiPurpose::Mapping, model: 'claude-opus-5-5')));

    expect($failure->reason)->toBe('reservation_exhausted');
    Http::assertNothingSent();
});

it('offers no refusal fallback whose price is not confirmed', function (): void {
    fakeAi();
    setModel('claude-opus-5-5', 'refusal_fallbacks', ['claude-opus-5']);
    config(['ai.models' => array_replace((array) config('ai.models'), ['claude-opus-5' => ['provider' => 'anthropic', 'input' => null, 'output' => null, 'cached_input' => null, 'max_input_tokens' => null, 'priced_on' => null]])]);

    expect(resolve(Pricing::class)->refusalFallbacks('claude-opus-5-5'))->toBe([]);
});

it('checks every answer against its value schema, whatever the provider promised', function (): void {
    $tenant = aiWorkspace();
    $fakes = fakeAi();
    $reservation = reserveFor($tenant);
    $fakes['gemini']->push(new ProviderResult(['p-1' => ['text' => 'ok'], 'p-2' => ['text' => 42]], new AiUsage(inputTokens: 100, outputTokens: 10), 'gemini-2.5-flash'));

    $failure = aiFailure(fn (): AiResponse => generateAs($tenant, aiRequest($tenant, $reservation->id)));

    expect($failure)->toBeInstanceOf(AiTerminal::class)
        ->and($failure->reason)->toBe(AiTerminal::INVALID_ANSWER)
        ->and(engineCalls($tenant)[0]->millicredits)->toBeGreaterThan(0);
});

it('builds a provider only for the hosts that have a key', function (?string $gemini, ?string $anthropic, bool $hasGemini, bool $hasAnthropic): void {
    config(['ai.providers.gemini.key' => $gemini, 'ai.providers.anthropic.key' => $anthropic]);

    $providers = Providers::fromConfig(fake: false, production: true);

    expect($providers->has('gemini'))->toBe($hasGemini)
        ->and($providers->has('anthropic'))->toBe($hasAnthropic);
    if ($hasGemini) {
        expect($providers->get('gemini'))->toBeInstanceOf(GeminiProvider::class);
    }
    if ($hasAnthropic) {
        expect($providers->get('anthropic'))->toBeInstanceOf(ClaudeProvider::class);
    }
})->with([
    'none' => [null, null, false, false],
    'empty strings' => ['', '', false, false],
    'gemini only' => ['k', null, true, false],
    'both' => ['k', 'k', true, true],
]);

describe('egress guard', function (): void {
    it('refuses a request to a model host that did not come through a provider', function (string $url): void {
        Http::fake();

        expect(fn () => Http::post($url, ['contents' => []]))->toThrow(RuntimeException::class, 'did not come through the AI gateway');
        Http::assertNothingSent();
    })->with([
        'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent',
        'https://api.anthropic.com/v1/messages',
        'https://API.ANTHROPIC.COM/v1/messages',
        'https://europe-west4-aiplatform.googleapis.com/v1/projects/p/locations/l/publishers/google/models/m:generateContent',
        'https://api.openai.com/v1/chat/completions',
    ]);

    it('lets every other host through untouched', function (): void {
        Http::fake(['shop.example/*' => Http::response('ok')]);

        expect(Http::get('https://shop.example/feed.xml')->body())->toBe('ok');
    });
});
