<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Jobs;

use App\Domain\AI\Contracts\AiClient;
use App\Domain\AI\Exceptions\AiException;
use App\Domain\Credits\Actions\SettleReservation;
use App\Domain\Mapping\Actions\StoreDecisions;
use App\Domain\Mapping\Data\MappingInput;
use App\Domain\Mapping\Data\Proposal;
use App\Domain\Mapping\MappingPrompt;
use App\Domain\Mapping\Models\Mapping;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Throwable;

/**
 * Runs an AI mapping suggestion as the workspace that asked for it: one gateway call per batch of
 * columns, every answer through the mapping pipeline, then the reservation is settled (what was
 * consumed is charged, the rest released). One attempt: the gateway already retried; a person can
 * ask again.
 */
#[Timeout(self::TIMEOUT)]
#[Tries(1)]
final class SuggestMappingJob implements ShouldQueue
{
    use Queueable;

    public const int TIMEOUT = 1_800;

    public function __construct(
        public readonly int $mappingId,
        public readonly int $tenantId,
        public readonly int $reservationId,
        public readonly ?string $instructions = null,
    ) {}

    public function handle(AiClient $ai, MappingPrompt $prompt, StoreDecisions $store, SettleReservation $settle): void
    {
        $mapping = Mapping::query()->find($this->mappingId);
        if (! $mapping instanceof Mapping) {
            $settle->handle($this->reservationId);

            return;
        }
        $mapping->forceFill(['ai_status' => 'running'])->save();

        try {
            $input = MappingInput::fromImport(StoreDecisions::profileOf($mapping));
            $proposals = [];
            $failure = null;
            foreach ($prompt->requests($mapping->tenant_id, $this->reservationId, $input, $mapping->source_locale, $this->instructions) as $request) {
                try {
                    $proposals = [...$proposals, ...$prompt->proposals($input, $ai->generate($request)->values)];
                } catch (AiException $exception) {
                    $failure = $exception->reason;

                    break;
                }
            }

            /** @var list<Proposal> $proposals */
            if ($proposals !== []) {
                $mapping = $store->handle($mapping, $proposals);
            }
            $mapping->forceFill(['ai_status' => $failure === null ? 'done' : 'failed', 'ai_failure' => $failure])->save();
        } finally {
            $settle->handle($this->reservationId);
        }
    }

    /** Timed out or crashed: the mapping must not stay "running", and the hold is released. */
    public function failed(?Throwable $exception): void
    {
        resolve(TenantContext::class)->run($this->tenantId, function (): void {
            Mapping::query()->whereKey($this->mappingId)->whereIn('ai_status', ['queued', 'running'])
                ->update(['ai_status' => 'failed', 'ai_failure' => 'interrupted']);
            resolve(SettleReservation::class)->handle($this->reservationId);
        });
    }
}
