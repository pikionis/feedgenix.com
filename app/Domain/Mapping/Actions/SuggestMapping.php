<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Actions;

use App\Domain\AI\CallEstimate;
use App\Domain\AI\Exceptions\AiDenied;
use App\Domain\AI\Exceptions\AiTerminal;
use App\Domain\Credits\Actions\ReserveCredits;
use App\Domain\Credits\Exceptions\InsufficientCredits;
use App\Domain\Mapping\Data\MappingInput;
use App\Domain\Mapping\Jobs\SuggestMappingJob;
use App\Domain\Mapping\MappingPrompt;
use App\Domain\Mapping\Models\Mapping;

/**
 * Asks the AI to improve a mapping (or to apply a seller's corrections). The worst case is known
 * before anything is spent: it is reserved first (no reservation, no call), then the job runs. A
 * suggestion already queued or running is not asked for twice.
 */
final readonly class SuggestMapping
{
    public function __construct(
        private MappingPrompt $prompt,
        private CallEstimate $estimate,
        private ReserveCredits $reserve,
    ) {}

    /**
     * The most a suggestion can cost, in millicredits.
     *
     * @throws AiDenied when AI is off
     * @throws AiTerminal when no model can take it
     */
    public function worstCase(Mapping $mapping, ?string $instructions = null): int
    {
        $input = MappingInput::fromImport(StoreDecisions::profileOf($mapping));

        return $this->estimate->total($this->prompt->requests($mapping->tenant_id, null, $input, $mapping->source_locale, $instructions));
    }

    /**
     * @throws AiDenied
     * @throws AiTerminal
     * @throws InsufficientCredits
     */
    public function queue(Mapping $mapping, ?string $instructions = null): Mapping
    {
        if (in_array($mapping->ai_status, ['queued', 'running'], true)) {
            return $mapping;
        }

        $amount = $this->worstCase($mapping, $instructions);
        $reservation = $this->reserve->handle("mapping:{$mapping->id}:v{$mapping->version}:".now()->getTimestamp(), $amount);
        $mapping->forceFill(['ai_status' => 'queued', 'ai_failure' => null, 'ai_requested_at' => now()])->save();

        // A block closure: the job is queued here, while the workspace context is still set.
        (static function () use ($mapping, $reservation, $instructions): void {
            dispatch(new SuggestMappingJob($mapping->id, $mapping->tenant_id, $reservation->id, $instructions));
        })();

        return $mapping;
    }
}
