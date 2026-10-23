<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\AiProvider;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiUsage;
use App\Domain\AI\Data\ProviderResult;
use App\Domain\AI\Exceptions\AiRetryable;
use App\Domain\AI\Exceptions\AiTerminal;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use LogicException;

/**
 * The Gemini API (generateContent), paid tier only: free-tier content may be used to improve
 * Google's products (ADR 0004). Legacy GeminiApiClient defects fixed here: the key travelled in the
 * URL and leaked through exception messages (D11; now the x-goog-api-key header, and no message
 * ever repeats a request); thinking tokens were not counted; SAFETY and RECITATION came back as
 * empty answers instead of typed failures.
 */
final readonly class GeminiProvider implements AiProvider
{
    private const array BLOCKED = ['SAFETY', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY', 'LANGUAGE'];

    public function __construct(
        private string $apiKey,
        private string $baseUrl,
        private int $timeoutSeconds,
    ) {}

    public function call(AiRequest $request): ProviderResult
    {
        $model = $request->model ?? throw new LogicException('The router names the model before the provider.');

        try {
            $response = Http::withHeaders(['x-goog-api-key' => $this->apiKey, AiEgressGuard::HEADER => 'gemini'])
                ->acceptJson()
                ->timeout($this->timeoutSeconds)
                ->post("{$this->baseUrl}/models/{$model}:generateContent", [
                    'systemInstruction' => ['parts' => [['text' => $request->prompt->system]]],
                    'contents' => [['role' => 'user', 'parts' => [['text' => Payload::userMessage($request)]]]],
                    'generationConfig' => [
                        'maxOutputTokens' => $request->maxOutputTokens,
                        'responseMimeType' => 'application/json',
                        'responseJsonSchema' => Payload::answerSchema($request),
                    ],
                ]);
        } catch (ConnectionException) {
            throw new AiRetryable(AiRetryable::NETWORK, 'Gemini could not be reached.');
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        return $this->result($response, $model);
    }

    private function result(Response $response, string $model): ProviderResult
    {
        $body = (array) $response->json();
        $usage = $this->usage((array) ($body['usageMetadata'] ?? []));

        $blockReason = $body['promptFeedback']['blockReason'] ?? null;
        $candidate = $body['candidates'][0] ?? null;
        if (is_string($blockReason) || ! is_array($candidate)) {
            throw new AiTerminal(AiTerminal::BLOCKED, 'Gemini blocked the prompt.', $usage);
        }

        $finish = (string) ($candidate['finishReason'] ?? 'STOP');
        if (in_array($finish, self::BLOCKED, true)) {
            throw new AiTerminal(AiTerminal::BLOCKED, "Gemini blocked the answer ({$finish}).", $usage);
        }
        if ($finish === 'RECITATION') {
            throw new AiTerminal(AiTerminal::RECITATION, 'Gemini withheld the answer as a recitation.', $usage);
        }

        // Thought parts are reasoning, not the answer.
        $text = '';
        foreach ((array) ($candidate['content']['parts'] ?? []) as $part) {
            if (is_array($part) && ($part['thought'] ?? false) !== true && is_string($part['text'] ?? null)) {
                $text .= $part['text'];
            }
        }

        if (in_array($finish, ['MAX_TOKENS', 'CONTINUATION'], true)) {
            return new ProviderResult([], $usage, $model, truncated: true);
        }
        if ($finish !== 'STOP') {
            throw AiTerminal::malformed($usage);
        }

        // Billed at the requested model's price: modelVersion can name a dated snapshot of it.
        return new ProviderResult(Payload::parse($text, $usage), $usage, $model);
    }

    /**
     * Gemini counts cached tokens inside the prompt and reports thoughts beside the candidates;
     * both thoughts and candidates are billed as output.
     *
     * @param  array<array-key, mixed>  $meta
     */
    private function usage(array $meta): AiUsage
    {
        $prompt = (int) ($meta['promptTokenCount'] ?? 0);
        $cached = (int) ($meta['cachedContentTokenCount'] ?? 0);

        return new AiUsage(
            inputTokens: max(0, $prompt - $cached) + (int) ($meta['toolUsePromptTokenCount'] ?? 0),
            outputTokens: (int) ($meta['candidatesTokenCount'] ?? 0),
            thinkingTokens: (int) ($meta['thoughtsTokenCount'] ?? 0),
            cachedInputTokens: $cached,
        );
    }

    private function failure(Response $response): AiRetryable|AiTerminal
    {
        $status = $response->status();

        return match (true) {
            $status === 429 => AiRetryable::rateLimited($this->retryAfter($response)),
            $status === 503 => new AiRetryable(AiRetryable::OVERLOADED, 'Gemini is overloaded.', $this->retryAfter($response)),
            $status === 504 || $status === 408 => new AiRetryable(AiRetryable::TIMEOUT, 'Gemini timed out.'),
            $status >= 500 => new AiRetryable(AiRetryable::SERVER_ERROR, "Gemini answered {$status}."),
            $status === 401 || $status === 403 => new AiTerminal(AiTerminal::PROVIDER_AUTH, 'Gemini refused the API key.'),
            $status === 404 => new AiTerminal(AiTerminal::UNKNOWN_MODEL, 'Gemini does not know this model.'),
            default => new AiTerminal(AiTerminal::BAD_REQUEST, "Gemini rejected the request ({$status})."),
        };
    }

    /** The Retry-After header, or the RetryInfo detail Google puts in the error body ("12s"). */
    private function retryAfter(Response $response): ?int
    {
        $header = $response->header('Retry-After');
        if (ctype_digit($header)) {
            return (int) $header;
        }
        foreach ((array) ($response->json('error.details') ?? []) as $detail) {
            $delay = is_array($detail) ? ($detail['retryDelay'] ?? null) : null;
            if (is_string($delay) && preg_match('/^(\d+)(?:\.\d+)?s$/', $delay, $match) === 1) {
                return (int) $match[1] + 1;
            }
        }

        return null;
    }
}
