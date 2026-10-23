<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use Anthropic\Beta\Messages\BetaFallbackMessageIterationUsage;
use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaMessageIterationUsage;
use Anthropic\Beta\Messages\BetaOutputConfig\Effort;
use Anthropic\Beta\Messages\BetaUsage;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\InternalServerException;
use Anthropic\Core\Exceptions\NotFoundException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\ErrorType;
use Anthropic\RequestOptions;
use App\Domain\AI\Contracts\AiProvider;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiUsage;
use App\Domain\AI\Data\BilledUsage;
use App\Domain\AI\Data\ProviderResult;
use App\Domain\AI\Exceptions\AiRetryable;
use App\Domain\AI\Exceptions\AiTerminal;
use InvalidArgumentException;
use LogicException;

/**
 * The Claude Messages API through the official SDK, over Laravel's HTTP client (LaravelTransporter).
 * The answer is constrained with structured outputs (output_config.format); forced tool use is
 * rejected by current models. The SDK's own retries are off: the gateway's Retry decides.
 *
 * Legacy ClaudeApiClient defects fixed here: stop_reason was ignored, so a cut-off or refused
 * answer was read as complete; thinking tokens were not billed. Claude reports thinking inside
 * output_tokens, so it is split out here and never billed twice.
 *
 * A server-side refusal fallback is sent only for models whose fallbacks all have confirmed prices,
 * because every hop is billed at its own model's rate (usage.iterations).
 */
final readonly class ClaudeProvider implements AiProvider
{
    private const string FALLBACK_BETA = 'server-side-fallback-2026-06-01';

    /**
     * @param  array<string, Effort>  $effort  model => effort
     * @param  array<string, list<string>>  $fallbacks  model => priced refusal fallbacks
     */
    public function __construct(
        private Client $client,
        private array $effort = [],
        private array $fallbacks = [],
    ) {}

    /**
     * @param  array<string, string>  $effort
     * @param  array<string, list<string>>  $fallbacks
     */
    public static function make(string $apiKey, string $baseUrl, int $timeoutSeconds, array $effort, array $fallbacks): self
    {
        $client = new Client(
            apiKey: $apiKey,
            baseUrl: $baseUrl,
            requestOptions: RequestOptions::with(timeout: (float) $timeoutSeconds, maxRetries: 0, transporter: new LaravelTransporter($timeoutSeconds)),
            openTelemetry: false,
        );

        $efforts = [];
        foreach ($effort as $model => $level) {
            $efforts[$model] = Effort::tryFrom($level) ?? throw new InvalidArgumentException("[ai.models.{$model}.effort] must be low, medium, high, xhigh or max.");
        }

        return new self($client, $efforts, $fallbacks);
    }

    public function call(AiRequest $request): ProviderResult
    {
        $model = $request->model ?? throw new LogicException('The router names the model before the provider.');
        $fallbacks = $this->fallbacks[$model] ?? [];
        $outputConfig = ['format' => ['type' => 'json_schema', 'schema' => Payload::answerSchema($request)]];
        if (isset($this->effort[$model])) {
            $outputConfig['effort'] = $this->effort[$model];
        }

        try {
            $message = $this->client->beta->messages->create(
                maxTokens: $request->maxOutputTokens,
                messages: [['role' => 'user', 'content' => Payload::userMessage($request)]],
                model: $model,
                fallbacks: $fallbacks === [] ? null : array_map(static fn (string $fallback): array => ['model' => $fallback], $fallbacks),
                outputConfig: $outputConfig,
                system: $request->prompt->system,
                betas: $fallbacks === [] ? null : [self::FALLBACK_BETA],
            );
        } catch (RateLimitException $limited) {
            throw AiRetryable::rateLimited($this->retryAfter($limited));
        } catch (InternalServerException $failed) {
            throw $failed->status === 529 || $failed->type === ErrorType::OVERLOADED_ERROR
                ? new AiRetryable(AiRetryable::OVERLOADED, 'Claude is overloaded.', $this->retryAfter($failed))
                : new AiRetryable(AiRetryable::SERVER_ERROR, "Claude answered {$failed->status}.");
        } catch (APITimeoutException) {
            throw new AiRetryable(AiRetryable::TIMEOUT, 'Claude timed out.');
        } catch (APIConnectionException) {
            throw new AiRetryable(AiRetryable::NETWORK, 'Claude could not be reached.');
        } catch (AuthenticationException|PermissionDeniedException) {
            throw new AiTerminal(AiTerminal::PROVIDER_AUTH, 'Claude refused the API key.');
        } catch (NotFoundException) {
            throw new AiTerminal(AiTerminal::UNKNOWN_MODEL, 'Claude does not know this model.');
        } catch (APIStatusException $rejected) {
            // Never the SDK's message: it repeats the response body. The request it holds has the key.
            throw match (true) {
                $rejected->status === 402 || $rejected->type === ErrorType::BILLING_ERROR => new AiTerminal(AiTerminal::PROVIDER_BILLING, 'The Anthropic account cannot be billed.'),
                $rejected->status === 413 => new AiTerminal(AiTerminal::PROMPT_TOO_LARGE, 'The request is too large for Claude.'),
                default => new AiTerminal(AiTerminal::BAD_REQUEST, "Claude rejected the request ({$rejected->status})."),
            };
        }

        return $this->result($message, $model);
    }

    private function result(BetaMessage $message, string $model): ProviderResult
    {
        $usage = $this->usage($message->usage);
        $billed = $this->billed($message->usage, $model, $usage);

        return match ($message->stopReason) {
            'end_turn', 'stop_sequence' => new ProviderResult(Payload::parse($this->text($message), $usage), $usage, $model, billed: $billed),
            'max_tokens' => new ProviderResult([], $usage, $model, truncated: true, billed: $billed),
            'refusal' => throw new AiTerminal(AiTerminal::REFUSED, 'Claude declined the request.', $usage, $billed),
            'model_context_window_exceeded' => throw new AiTerminal(AiTerminal::PROMPT_TOO_LARGE, 'The prompt exceeds the context window.', $usage, $billed),
            default => throw new AiTerminal(AiTerminal::MALFORMED, 'Claude stopped without an answer.', $usage, $billed),
        };
    }

    private function text(BetaMessage $message): string
    {
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text' && property_exists($block, 'text') && is_string($block->text)) {
                $text .= $block->text;
            }
        }

        return $text;
    }

    /** Thinking is part of output_tokens on Claude: split it out so it is shown, never billed twice. */
    private function usage(BetaUsage $usage): AiUsage
    {
        $thinking = min($usage->outputTokensDetails->thinkingTokens ?? 0, $usage->outputTokens);

        return new AiUsage(
            // Cache writes only happen with cache_control, which the gateway never sends.
            inputTokens: $usage->inputTokens + ($usage->cacheCreationInputTokens ?? 0),
            outputTokens: $usage->outputTokens - $thinking,
            thinkingTokens: $thinking,
            cachedInputTokens: $usage->cacheReadInputTokens ?? 0,
        );
    }

    /**
     * Per-model usage when a refusal fallback ran: usage.iterations is the source of truth, and the
     * top-level usage covers only the hop that produced the message.
     *
     * @return list<BilledUsage>
     */
    private function billed(BetaUsage $usage, string $requested, AiUsage $total): array
    {
        $iterations = $usage->iterations ?? [];
        $fellBack = array_filter($iterations, static fn (mixed $iteration): bool => $iteration instanceof BetaFallbackMessageIterationUsage) !== [];
        if (! $fellBack) {
            return [new BilledUsage($requested, $total)];
        }

        $billed = [];
        foreach ($iterations as $iteration) {
            if ($iteration instanceof BetaFallbackMessageIterationUsage || $iteration instanceof BetaMessageIterationUsage) {
                $billed[] = new BilledUsage(
                    $iteration instanceof BetaFallbackMessageIterationUsage ? $iteration->model : ($iteration->model ?? $requested),
                    new AiUsage(
                        inputTokens: $iteration->inputTokens + $iteration->cacheCreationInputTokens,
                        outputTokens: $iteration->outputTokens,
                        cachedInputTokens: $iteration->cacheReadInputTokens,
                    ),
                );
            }
        }

        return $billed;
    }

    private function retryAfter(APIStatusException $exception): ?int
    {
        $header = $exception->response?->getHeaderLine('retry-after') ?? '';

        return ctype_digit($header) ? (int) $header : null;
    }
}
