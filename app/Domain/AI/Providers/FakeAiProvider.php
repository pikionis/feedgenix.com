<?php

declare(strict_types=1);

namespace App\Domain\AI\Providers;

use App\Domain\AI\Contracts\AiProvider;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiUsage;
use App\Domain\AI\Data\ProviderResult;
use App\Domain\AI\Exceptions\AiException;
use Closure;

/**
 * The model host of tests and e2e (ADR 0004, ADR 0010). It sits under the real gateway, so policy,
 * reservations, recording and splitting always run. Scripted steps are used in order: a result, an
 * exception to throw (429, timeout, RECITATION…), or a closure building the answer. With no script
 * left it echoes every item back under its id.
 */
final class FakeAiProvider implements AiProvider
{
    /** @var list<ProviderResult|AiException|Closure(AiRequest): ProviderResult> */
    private array $script = [];

    /** @var list<AiRequest> every request it received, in order */
    public array $calls = [];

    /** @param ProviderResult|AiException|Closure(AiRequest): ProviderResult ...$steps */
    public function push(ProviderResult|AiException|Closure ...$steps): self
    {
        array_push($this->script, ...$steps);

        return $this;
    }

    public function call(AiRequest $request): ProviderResult
    {
        $this->calls[] = $request;
        $step = array_shift($this->script);

        return match (true) {
            $step === null => self::echo($request),
            $step instanceof AiException => throw $step,
            $step instanceof Closure => $step($request),
            default => $step,
        };
    }

    /** Every item back under its id, with a usage that grows with the input. */
    public static function echo(AiRequest $request, ?AiUsage $usage = null): ProviderResult
    {
        $values = [];
        $bytes = 0;
        foreach ($request->items as $item) {
            $values[$item->id] = ['text' => $item->text];
            $bytes += strlen($item->text);
        }

        return new ProviderResult(
            $values,
            $usage ?? new AiUsage(inputTokens: 100 + intdiv($bytes, 4), outputTokens: 10 * count($values)),
            $request->model ?? 'fake',
        );
    }
}
