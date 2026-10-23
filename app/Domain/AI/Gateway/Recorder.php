<?php

declare(strict_types=1);

namespace App\Domain\AI\Gateway;

use App\Domain\AI\Contracts\AiClient;
use App\Domain\AI\Data\AiRequest;
use App\Domain\AI\Data\AiResponse;
use App\Domain\AI\Data\AiUsage;
use App\Domain\AI\Data\BilledUsage;
use App\Domain\AI\Exceptions\AiDenied;
use App\Domain\AI\Exceptions\AiException;
use App\Domain\AI\Exceptions\AiRetryable;
use App\Domain\AI\Exceptions\AiTerminal;
use App\Domain\AI\Models\EngineCall;
use App\Domain\AI\Pricing;
use App\Domain\Credits\Actions\ConsumeReservation;
use App\Domain\Credits\Data\Charge;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use LogicException;

/**
 * Every attempt that reaches a provider leaves one engine_calls row, failures included, and every
 * billed attempt is charged to its reservation at exactly provider cost × 1.1 (ADR 0005), in the
 * same database transaction as its row. Before each attempt the reservation must still cover its
 * worst case, so a run of retries cannot spend past what was reserved.
 */
final readonly class Recorder implements AiClient
{
    public function __construct(
        private AiClient $next,
        private Pricing $pricing,
        private ConsumeReservation $consume,
        private int $outerTransactionLevel,
    ) {}

    public function generate(AiRequest $request): AiResponse
    {
        if (DB::transactionLevel() > $this->outerTransactionLevel) {
            // A rollback of the caller's transaction would erase the charge of a call that was billed.
            throw new LogicException('A model call must not run inside a database transaction.');
        }
        $model = $request->model ?? throw new LogicException('The router names the model before the recorder.');
        $reservation = Reservations::open($request);
        if ($reservation->remaining() < $this->pricing->worstCase($request, $model)) {
            throw AiDenied::because('reservation_exhausted', 'The reservation does not cover another attempt.');
        }

        $started = hrtime(true);
        try {
            $response = $this->next->generate($request);
        } catch (AiException $failure) {
            $status = $failure instanceof AiRetryable ? 'retryable' : 'terminal';
            $billed = $failure instanceof AiTerminal && $failure->billed !== []
                ? $failure->billed
                : [new BilledUsage($model, $failure->usage ?? new AiUsage)];
            $this->record($request, $model, $reservation->id, $failure->usage ?? new AiUsage, $billed, $started, $status, $failure->reason);

            throw $failure;
        }

        $charge = $this->record($request, $response->model, $reservation->id, $response->usage, $response->billedUsage(), $started, 'ok', null);

        return $response->withCharge($charge->charged);
    }

    /** @param non-empty-list<BilledUsage> $billed */
    private function record(AiRequest $request, string $model, int $reservationId, AiUsage $usage, array $billed, int|float $started, string $status, ?string $error): Charge
    {
        $latencyMs = (int) ((hrtime(true) - $started) / 1_000_000);
        $cost = $this->cost($request, $billed);
        $millicredits = $this->pricing->millicredits($cost);

        $charge = DB::transaction(function () use ($request, $model, $reservationId, $usage, $latencyMs, $status, $error, $cost, $millicredits): Charge {
            $charge = $this->consume->handle($reservationId, $millicredits);

            EngineCall::query()->forceCreate([
                'reservation_id' => $reservationId,
                'purpose' => $request->purpose->value,
                'provider' => (string) $request->provider,
                'model' => $model,
                'prompt' => $request->prompt->ref(),
                'attempt' => $request->attempt,
                'item_count' => count($request->items),
                'input_tokens' => $usage->inputTokens,
                'output_tokens' => $usage->outputTokens,
                'thinking_tokens' => $usage->thinkingTokens,
                'cached_input_tokens' => $usage->cachedInputTokens,
                'latency_ms' => $latencyMs,
                'status' => $status,
                'error_code' => $error,
                'cost_usd' => (string) $cost->toScale(10, RoundingMode::Up),
                'millicredits' => $charge->charged,
                'unbilled_millicredits' => $charge->unbilled,
            ]);

            return $charge;
        });

        if ($charge->unbilled > 0) {
            Log::warning('AI attempt cost more than its reservation still held; the difference is unbilled.', [
                'tenant_id' => $request->tenantId,
                'reservation_id' => $reservationId,
                'model' => $model,
                'unbilled_millicredits' => $charge->unbilled,
            ]);
        }

        return $charge;
    }

    /**
     * Every hop at its own model's price. A hop on a model without a price cannot be charged: it is
     * logged for staff (the router and Pricing only ever offer priced models, so this means a bug).
     *
     * @param  non-empty-list<BilledUsage>  $billed
     */
    private function cost(AiRequest $request, array $billed): BigDecimal
    {
        $cost = BigDecimal::zero();
        foreach ($billed as $hop) {
            if (! $this->pricing->isPriced($hop->model)) {
                Log::critical('An AI attempt was billed on a model without a price; it is not charged.', [
                    'tenant_id' => $request->tenantId,
                    'model' => $hop->model,
                ]);

                continue;
            }
            $cost = $cost->plus($this->pricing->costUsd($hop->model, $hop->usage));
        }

        return $cost;
    }
}
