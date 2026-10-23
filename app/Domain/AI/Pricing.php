<?php

declare(strict_types=1);

namespace App\Domain\AI;

use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiUsage;
use App\Domain\AI\Exceptions\AiTerminal;
use App\Domain\Credits\Millicredits;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use InvalidArgumentException;

/**
 * What a model call costs and what it is charged (ADR 0005): provider cost from the reported usage
 * (thinking tokens billed as output), at the configured FX rate, × 1.1, rounded up to a whole
 * millicredit. Exact decimals throughout. Internal only: nothing here may reach a response.
 */
final readonly class Pricing
{
    /** The owner's rule: every call, on every model, at exactly provider cost × 1.1. Code, not config. */
    public const string MARKUP = '1.1';

    /** Bytes a provider may add around the prompt (roles, the response schema, JSON framing). */
    private const int FRAMING_BYTES = 2048;

    /** Bytes a provider may add around each item (its id key, quotes, separators). */
    private const int ITEM_FRAMING_BYTES = 32;

    /**
     * @param  array<string, array{input: BigDecimal, output: BigDecimal, cached_input: BigDecimal, max_input_tokens: int|null}>  $prices
     * @param  array<string, list<string>>  $fallbacks  model => refusal fallbacks, every one of them priced
     */
    public function __construct(
        private array $prices,
        private BigDecimal $usdToEur,
        private array $fallbacks = [],
    ) {}

    public static function fromConfig(): self
    {
        $prices = [];
        foreach ((array) config('ai.models', []) as $model => $entry) {
            $entry = (array) $entry;
            $input = self::price($model, $entry, 'input');
            $output = self::price($model, $entry, 'output');
            $cached = self::price($model, $entry, 'cached_input');
            if (! $input instanceof BigDecimal || ! $output instanceof BigDecimal || ! $cached instanceof BigDecimal) {
                continue; // not confirmed: not callable
            }
            $max = $entry['max_input_tokens'] ?? null;
            $prices[(string) $model] = [
                'input' => $input,
                'output' => $output,
                'cached_input' => $cached,
                'max_input_tokens' => is_int($max) ? $max : null,
            ];
        }

        // A refusal fallback bills its hop at its own model's rate: offered only when all are priced.
        $fallbacks = [];
        foreach ((array) config('ai.models', []) as $model => $entry) {
            $chain = array_values(array_map(strval(...), (array) (((array) $entry)['refusal_fallbacks'] ?? [])));
            if ($chain !== [] && isset($prices[(string) $model]) && array_diff($chain, array_keys($prices)) === []) {
                $fallbacks[(string) $model] = $chain;
            }
        }

        return new self($prices, self::decimal('ai.usd_to_eur', config('ai.usd_to_eur'), positive: true), $fallbacks);
    }

    /** @return list<string> the refusal fallbacks a call on $model may be served by */
    public function refusalFallbacks(string $model): array
    {
        return $this->fallbacks[$model] ?? [];
    }

    /** @return array<string, list<string>> */
    public function allRefusalFallbacks(): array
    {
        return $this->fallbacks;
    }

    /** Only a model with every price confirmed is callable. */
    public function isPriced(string $model): bool
    {
        return isset($this->prices[$model]);
    }

    /** Provider cost of one attempt, in USD. Input tokens exclude cached ones (AiUsage). */
    public function costUsd(string $model, AiUsage $usage): BigDecimal
    {
        $price = $this->prices[$model] ?? throw $this->unpriced($model);

        return $price['input']->multipliedBy($usage->inputTokens)
            ->plus($price['cached_input']->multipliedBy($usage->cachedInputTokens))
            ->plus($price['output']->multipliedBy($usage->outputTokens + $usage->thinkingTokens))
            ->dividedBy(1_000_000, 16);
    }

    /** What the customer is charged for that cost: × FX × 1.1, rounded up to a whole millicredit. */
    public function millicredits(BigDecimal $costUsd): int
    {
        return Millicredits::fromEuros($costUsd->multipliedBy($this->usdToEur)->multipliedBy(self::MARKUP));
    }

    public function charge(string $model, AiUsage $usage): int
    {
        return $this->millicredits($this->costUsd($model, $usage));
    }

    /**
     * The most this call can be charged on $model: every input byte a token (a token never covers
     * less than one byte) plus framing, and every allowed output token used (thinking included, which
     * both providers count inside the output limit). A reservation that covers this can never be
     * overrun. With refusal fallbacks every hop may bill in full, so their worst cases add up.
     *
     * @throws AiTerminal when the prompt could pass the size the flat price holds for
     */
    public function worstCase(AiRequest $request, string $model): int
    {
        $total = 0;
        foreach ([$model, ...$this->refusalFallbacks($model)] as $hop) {
            $price = $this->prices[$hop] ?? throw $this->unpriced($hop);
            $inputTokens = $this->worstCaseInputTokens($request);
            if ($price['max_input_tokens'] !== null && $inputTokens > $price['max_input_tokens']) {
                throw new AiTerminal(AiTerminal::PROMPT_TOO_LARGE, 'The prompt is too large for one call; split the unit.');
            }
            $total += $this->charge($hop, new AiUsage(inputTokens: $inputTokens, outputTokens: $request->maxOutputTokens));
        }

        return $total;
    }

    /** Items travel as JSON (AiRequest::JSON_FLAGS), so their bound is their encoded size, escapes included. */
    private function worstCaseInputTokens(AiRequest $request): int
    {
        $bytes = self::FRAMING_BYTES
            + strlen($request->prompt->system)
            + strlen($request->prompt->instructions)
            + strlen(json_encode($request->valueSchema, AiRequest::JSON_FLAGS));
        foreach ($request->items as $item) {
            $bytes += self::ITEM_FRAMING_BYTES + strlen(json_encode([$item->id => $item->text], AiRequest::JSON_FLAGS));
        }

        return $bytes;
    }

    private function unpriced(string $model): AiTerminal
    {
        return new AiTerminal(AiTerminal::UNPRICED_MODEL, "Model [{$model}] has no confirmed price and is not callable.");
    }

    /** @param array<array-key, mixed> $entry */
    private static function price(int|string $model, array $entry, string $key): ?BigDecimal
    {
        $value = $entry[$key] ?? null;

        return $value === null ? null : self::decimal("ai.models.{$model}.{$key}", $value, positive: false);
    }

    private static function decimal(string $name, mixed $value, bool $positive): BigDecimal
    {
        // Strings only: a float literal in config would already have lost its exact value.
        if (! is_string($value)) {
            throw new InvalidArgumentException("[{$name}] must be a decimal string, like '1.25'.");
        }
        try {
            $decimal = BigDecimal::of($value);
        } catch (MathException) {
            throw new InvalidArgumentException("[{$name}] must be a decimal string, like '1.25'.");
        }
        if ($decimal->isNegative() || ($positive && $decimal->isZero())) {
            throw new InvalidArgumentException("[{$name}] must be ".($positive ? 'positive.' : 'zero or more.'));
        }

        return $decimal;
    }
}
