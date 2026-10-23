<?php

declare(strict_types=1);

namespace App\Domain\AI;

use App\Domain\AI\Data\AiItem;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiUsage;
use App\Domain\AI\Data\Prompt;
use App\Domain\AI\Exceptions\AiDenied;
use App\Domain\AI\Exceptions\AiTerminal;

/**
 * What a call may cost at most, in millicredits, before it is made: the amount to reserve
 * (ADR 0005, reserve before paid work). It is the worst case of the costliest model the call may
 * run on, the same bound the gateway enforces. Internal: a screen shows credits, never costs.
 */
final readonly class CallEstimate
{
    public function __construct(
        private Routes $routes,
        private Pricing $pricing,
        private bool $enabled,
    ) {}

    /**
     * @throws AiDenied when AI is switched off
     * @throws AiTerminal when no priced model on a configured provider can take the call
     */
    public function worstCase(AiRequest $request): int
    {
        if (! $this->enabled) {
            throw AiDenied::because('ai_disabled', 'AI is switched off.');
        }
        $worst = 0;
        foreach ($this->routes->candidates($request) as $candidate) {
            $worst = max($worst, $this->pricing->worstCase($request, $candidate['model']));
        }

        return $worst;
    }

    /** @param list<AiRequest> $requests */
    public function total(array $requests): int
    {
        return array_sum(array_map($this->worstCase(...), $requests));
    }

    /**
     * What a usage would be charged on the costliest model a purpose may run on: the building block
     * of estimates made before any request exists (a translation plan).
     *
     * @throws AiDenied when AI is switched off
     * @throws AiTerminal when no priced model on a configured provider can take the purpose
     */
    public function charge(AiPurpose $purpose, int $inputTokens, int $outputTokens): int
    {
        if (! $this->enabled) {
            throw AiDenied::because('ai_disabled', 'AI is switched off.');
        }
        $probe = new AiRequest(0, $purpose, new Prompt('estimate', '0', '', ''), [new AiItem('x', '')], [], 1, null);
        $charge = 0;
        foreach ($this->routes->candidates($probe) as $candidate) {
            $charge = max($charge, $this->pricing->charge($candidate['model'], new AiUsage(inputTokens: $inputTokens, outputTokens: $outputTokens)));
        }

        return $charge;
    }
}
