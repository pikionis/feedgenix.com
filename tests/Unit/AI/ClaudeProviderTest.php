<?php

declare(strict_types=1);

use App\Domain\AI\AiPurpose;
use App\Domain\AI\Data\AiItem;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\Prompt;
use App\Domain\AI\Data\ProviderResult;
use App\Domain\AI\Exceptions\AiException;
use App\Domain\AI\Exceptions\AiRetryable;
use App\Domain\AI\Exceptions\AiTerminal;
use App\Domain\AI\Providers\AiEgressGuard;
use App\Domain\AI\Providers\ClaudeProvider;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
 * The Claude provider: the official SDK over Laravel's HTTP client, against a faked Messages API.
 * Legacy ClaudeApiClient ignored stop_reason and never counted thinking.
 */
const CLAUDE_KEY = 'test-anthropic-key-NEVER-LOGGED';
const CLAUDE_URL = 'https://api.anthropic.com/v1/messages';

/** @param array<string, list<string>> $fallbacks */
function claude(array $fallbacks = []): ClaudeProvider
{
    return ClaudeProvider::make(CLAUDE_KEY, 'https://api.anthropic.com', 30, ['claude-opus-5-5' => 'medium'], $fallbacks);
}

function claudeRequest(): AiRequest
{
    return (new AiRequest(
        tenantId: 1,
        purpose: AiPurpose::Mapping,
        prompt: new Prompt('map', '1', 'You map product feed columns.', 'Map every column.'),
        items: [new AiItem('c-1', 'Titel'), new AiItem('c-2', 'Preis')],
        valueSchema: ['type' => 'object', 'properties' => ['field' => ['type' => ['string', 'null']]]],
        maxOutputTokens: 4000,
        reservationId: 1,
    ))->routedTo('claude-opus-5-5', 'anthropic');
}

/** @param array<string, mixed> $overrides */
function claudeMessage(array $overrides = []): array
{
    return array_replace([
        'id' => 'msg_test',
        'type' => 'message',
        'role' => 'assistant',
        'model' => 'claude-opus-5-5',
        'content' => [['type' => 'text', 'text' => json_encode(['items' => [
            ['id' => 'c-1', 'value' => ['field' => 'title']],
            ['id' => 'c-2', 'value' => ['field' => null]],
        ]])]],
        'stop_reason' => 'end_turn',
        'stop_sequence' => null,
        'usage' => [
            'input_tokens' => 1000,
            'output_tokens' => 800,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens' => 50,
            'output_tokens_details' => ['thinking_tokens' => 300],
        ],
    ], $overrides);
}

function claudeFailure(callable $call): AiException
{
    try {
        $call();
    } catch (AiException $failure) {
        return $failure;
    }

    throw new RuntimeException('Expected a typed failure.');
}

it('sends a structured-output request with the key in a header and nothing the API rejects', function (): void {
    Http::fake([CLAUDE_URL.'*' => Http::response(claudeMessage())]);

    claude()->call(claudeRequest());

    Http::assertSentCount(1);
    Http::assertSent(function (Request $request): bool {
        $body = $request->data();
        $schema = $body['output_config']['format']['schema'] ?? [];

        return $request->url() === CLAUDE_URL.'?beta=true'
            && $request->header('Authorization') === ['Bearer '.CLAUDE_KEY]
            && ! str_contains($request->url(), CLAUDE_KEY)
            && $request->hasHeader('anthropic-version')
            && ! $request->hasHeader(AiEgressGuard::HEADER)
            && ! $request->hasHeader('anthropic-beta')
            && $body['model'] === 'claude-opus-5-5'
            && $body['max_tokens'] === 4000
            && $body['system'] === 'You map product feed columns.'
            && $body['output_config']['format']['type'] === 'json_schema'
            && $body['output_config']['effort'] === 'medium'
            && $schema['properties']['items']['items']['properties']['id']['enum'] === ['c-1', 'c-2']
            && $schema['properties']['items']['items']['properties']['value']['additionalProperties'] === false
            && ! array_key_exists('fallbacks', $body)
            && ! array_key_exists('temperature', $body)
            && ! array_key_exists('tool_choice', $body);
    });
});

it('reads the answer and splits thinking out of output_tokens, so it is never billed twice', function (): void {
    Http::fake([CLAUDE_URL.'*' => Http::response(claudeMessage())]);

    $result = claude()->call(claudeRequest());

    expect($result->values)->toBe(['c-1' => ['field' => 'title'], 'c-2' => ['field' => null]])
        ->and([$result->usage->inputTokens, $result->usage->outputTokens, $result->usage->thinkingTokens, $result->usage->cachedInputTokens])
        ->toBe([1000, 500, 300, 50])
        ->and($result->usage->outputTokens + $result->usage->thinkingTokens)->toBe(800)
        ->and($result->billed)->toHaveCount(1);
});

it('reads stop_reason before the content', function (string $stop, ?string $reason): void {
    Http::fake([CLAUDE_URL.'*' => Http::response(claudeMessage(['stop_reason' => $stop, 'content' => $stop === 'refusal' ? [] : claudeMessage()['content']]))]);

    if ($reason === null) {
        expect(claude()->call(claudeRequest())->truncated)->toBeTrue();

        return;
    }
    $failure = claudeFailure(fn (): ProviderResult => claude()->call(claudeRequest()));
    expect($failure)->toBeInstanceOf(AiTerminal::class)
        ->and($failure->reason)->toBe($reason)
        ->and($failure->usage?->inputTokens)->toBe(1000);
})->with([
    'max_tokens' => ['max_tokens', null],
    'refusal' => ['refusal', AiTerminal::REFUSED],
    'context window' => ['model_context_window_exceeded', AiTerminal::PROMPT_TOO_LARGE],
    'pause_turn' => ['pause_turn', AiTerminal::MALFORMED],
]);

it('maps API errors onto typed failures, without the SDK retrying and without the key', function (int $status, array $headers, string $type, string $class, string $reason, ?int $retryAfter): void {
    Http::fake([CLAUDE_URL.'*' => Http::response(['type' => 'error', 'error' => ['type' => $type, 'message' => 'invalid x-api-key '.CLAUDE_KEY]], $status, $headers)]);

    $failure = claudeFailure(fn (): ProviderResult => claude()->call(claudeRequest()));

    Http::assertSentCount(1);
    expect($failure)->toBeInstanceOf($class)
        ->and($failure->reason)->toBe($reason)
        ->and($failure->getMessage())->not->toContain(CLAUDE_KEY)
        ->and($failure->getPrevious())->toBeNull();
    if ($failure instanceof AiRetryable) {
        expect($failure->retryAfterSeconds)->toBe($retryAfter);
    }
})->with([
    '429' => [429, ['retry-after' => '9'], 'rate_limit_error', AiRetryable::class, AiRetryable::RATE_LIMITED, 9],
    '529 overloaded' => [529, [], 'overloaded_error', AiRetryable::class, AiRetryable::OVERLOADED, null],
    '500' => [500, [], 'api_error', AiRetryable::class, AiRetryable::SERVER_ERROR, null],
    '401' => [401, [], 'authentication_error', AiTerminal::class, AiTerminal::PROVIDER_AUTH, null],
    '403' => [403, [], 'permission_error', AiTerminal::class, AiTerminal::PROVIDER_AUTH, null],
    '404' => [404, [], 'not_found_error', AiTerminal::class, AiTerminal::UNKNOWN_MODEL, null],
    '402 billing' => [402, [], 'billing_error', AiTerminal::class, AiTerminal::PROVIDER_BILLING, null],
    '413' => [413, [], 'request_too_large', AiTerminal::class, AiTerminal::PROMPT_TOO_LARGE, null],
    '400' => [400, [], 'invalid_request_error', AiTerminal::class, AiTerminal::BAD_REQUEST, null],
]);

it('treats an unreachable host as retryable', function (): void {
    Http::fake([CLAUDE_URL.'*' => Http::failedConnection()]);

    expect(claudeFailure(fn (): ProviderResult => claude()->call(claudeRequest()))->reason)->toBe(AiRetryable::NETWORK);
});

it('offers a refusal fallback when it is priced, and bills each hop on its own model', function (): void {
    Http::fake([CLAUDE_URL.'*' => Http::response(claudeMessage([
        'model' => 'claude-opus-5',
        'usage' => [
            'input_tokens' => 1000,
            'output_tokens' => 600,
            'cache_creation_input_tokens' => 0,
            'cache_read_input_tokens' => 0,
            'iterations' => [
                ['type' => 'message', 'model' => 'claude-opus-5-5', 'input_tokens' => 1000, 'output_tokens' => 20, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
                ['type' => 'fallback_message', 'model' => 'claude-opus-5', 'input_tokens' => 1000, 'output_tokens' => 600, 'cache_creation_input_tokens' => 0, 'cache_read_input_tokens' => 0],
            ],
        ],
    ]))]);

    $result = claude(['claude-opus-5-5' => ['claude-opus-5']])->call(claudeRequest());

    Http::assertSent(fn (Request $request): bool => $request->data()['fallbacks'] === [['model' => 'claude-opus-5']]
        && str_contains($request->header('anthropic-beta')[0] ?? '', 'server-side-fallback-2026-06-01'));
    expect(array_map(static fn ($hop): array => [$hop->model, $hop->usage->inputTokens, $hop->usage->outputTokens], $result->billed))
        ->toBe([['claude-opus-5-5', 1000, 20], ['claude-opus-5', 1000, 600]]);
});

it('refuses an effort the API does not know, at configuration time', function (): void {
    expect(fn (): ClaudeProvider => ClaudeProvider::make(CLAUDE_KEY, 'https://api.anthropic.com', 30, ['claude-opus-5-5' => 'extreme'], []))
        ->toThrow(InvalidArgumentException::class, 'effort');
});
