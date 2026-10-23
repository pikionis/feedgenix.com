<?php

declare(strict_types=1);

namespace App\Domain\Translation;

use App\Domain\AI\CallEstimate;
use App\Domain\AI\Contracts\AiClient;
use App\Domain\AI\Exceptions\AiDenied;
use App\Domain\AI\Exceptions\AiException;
use App\Domain\AI\Exceptions\AiRetryable;
use App\Domain\AI\Exceptions\AiTerminal;
use App\Domain\Catalog\Models\ProductText;
use App\Domain\Credits\Actions\ReserveCredits;
use App\Domain\Credits\Actions\SettleReservation;
use App\Domain\Credits\Exceptions\InsufficientCredits;
use App\Domain\Quality\QualityChecker;
use App\Domain\Translation\Models\WorkUnit;
use Illuminate\Support\Facades\DB;

/**
 * Runs one leased work unit (ADR 0006): read the sources it was planned from, reserve its worst
 * case, call the gateway, check every answer, write the translations fenced by source, remember
 * the good ones, settle.
 *
 *  - A unit whose answer is misaligned, cut off or refused splits in two, down to depth 6, so one
 *    bad item never sinks its neighbours (legacy D12: answers were matched by position).
 *  - Retryable failures come back later with a backoff; after 5 attempts the unit fails.
 *  - No credits, AI off or paused: the unit is held, not failed, and resumes when that clears.
 *  - Only translations that pass the quality checks go into the memory (the legacy memory stored
 *    every answer before checking it).
 */
final readonly class UnitExecutor
{
    public const int MAX_DEPTH = 6;

    public const int MAX_ATTEMPTS = 5;

    /** Locale fixes applied to every translation, whatever the model did. */
    private const array LOCALE_FIXES = ['de-CH' => ['ß' => 'ss', 'ẞ' => 'SS']];

    public function __construct(
        private AiClient $ai,
        private TranslationPrompt $prompt,
        private CallEstimate $estimate,
        private ReserveCredits $reserve,
        private SettleReservation $settle,
        private WorkDispatcher $dispatcher,
    ) {}

    public function run(int $unitId, string $leaseToken): void
    {
        $unit = WorkUnit::query()->find($unitId);
        // Fenced: an expired lease handed the unit to someone else; this worker does nothing.
        if (! $unit instanceof WorkUnit || $unit->status !== 'leased' || $unit->lease_token !== $leaseToken) {
            return;
        }

        $texts = $this->sources($unit);
        if ($texts === []) {
            $this->finish($unit, 'done');

            return;
        }

        $request = $this->prompt->request($unit->tenant_id, null, $unit->field, $unit->source_locale, $unit->target_locale, $texts);
        try {
            $reservation = $this->reserve->handle("unit:{$unit->id}:a{$unit->attempts}", $this->estimate->worstCase($request));
        } catch (InsufficientCredits) {
            $this->hold($unit, 'credits');

            return;
        } catch (AiException $unavailable) {
            $this->hold($unit, $unavailable->reason);

            return;
        }

        // The outcome is applied after the reservation is settled: finishing pumps the next unit,
        // which must not find this unit's hold still taken.
        try {
            $response = $this->ai->generate($this->prompt->request($unit->tenant_id, $reservation->id, $unit->field, $unit->source_locale, $unit->target_locale, $texts));
            $this->store($unit, $texts, $response->values, $response->model);
            $outcome = fn () => $this->finish($unit, 'done');
        } catch (AiTerminal $terminal) {
            $outcome = count($texts) > 1 && $unit->depth < self::MAX_DEPTH
                ? fn () => $this->split($unit, $texts)
                : fn () => $this->finish($unit, 'failed', $terminal->reason);
        } catch (AiRetryable $retryable) {
            $outcome = $unit->attempts >= self::MAX_ATTEMPTS
                ? fn () => $this->finish($unit, 'failed', $retryable->reason)
                : fn () => $this->later($unit, max($retryable->retryAfterSeconds ?? 0, 30 * $unit->attempts));
        } catch (AiDenied $denied) {
            $outcome = fn () => $this->hold($unit, $denied->reason);
        } finally {
            $this->settle->handle($reservation->id);
        }

        $outcome();
    }

    /** @return array<string, string> item id ("p{product}") => source text, for the items whose source is unchanged */
    private function sources(WorkUnit $unit): array
    {
        $current = ProductText::query()
            ->whereIn('product_id', array_map(intval(...), array_keys($unit->items)))
            ->where('field', $unit->field)->where('locale', $unit->source_locale)->where('role', 'source')
            ->get(['product_id', 'value', 'source_hash'])->keyBy('product_id');

        $texts = [];
        foreach ($unit->items as $productId => $hash) {
            $source = $current->get((int) $productId);
            if ($source instanceof ProductText && $source->source_hash === $hash && $source->value !== null) {
                $texts["p{$productId}"] = $source->value;
            }
        }

        return $texts;
    }

    /**
     * @param  array<string, string>  $texts
     * @param  array<array-key, mixed>  $values
     */
    private function store(WorkUnit $unit, array $texts, array $values, string $model): void
    {
        $prompt = TranslationPrompt::NAME.'@'.TranslationPrompt::VERSION;
        foreach ($texts as $id => $source) {
            $answer = $values[$id] ?? null;
            $translation = is_array($answer) && is_string($answer['text'] ?? null) ? $answer['text'] : '';
            $translation = strtr($translation, self::LOCALE_FIXES[$unit->target_locale] ?? []);
            $quality = QualityChecker::check($unit->field, $source, $translation, $unit->target_locale);
            $productId = (int) substr($id, 1);
            $hash = (string) ($unit->items[$productId] ?? $unit->items[(string) $productId] ?? '');

            $written = TargetWriter::write($unit->tenant_id, $productId, $unit->field, $unit->source_locale, $hash, $unit->target_locale, $translation, 'ai', $quality->isHardFail() ? 'qa_failed' : 'ready', $prompt, $model, $quality->toArray());
            if ($written && ! $quality->isHardFail()) {
                DB::table('translation_memory')->insertOrIgnore([
                    'tenant_id' => $unit->tenant_id, 'field' => $unit->field, 'source_locale' => $unit->source_locale,
                    'target_locale' => $unit->target_locale, 'source_hash' => $hash, 'value' => $translation,
                    'prompt' => $prompt, 'model' => $model, 'hits' => 0, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    /** @param array<string, string> $texts */
    private function split(WorkUnit $unit, array $texts): void
    {
        $items = array_intersect_key($unit->items, array_flip(array_map(static fn (string $id): string => substr($id, 1), array_keys($texts))));
        foreach (array_chunk($items, max(1, (int) ceil(count($items) / 2)), true) as $half) {
            WorkUnit::query()->forceCreate([
                'run_id' => $unit->run_id, 'feed_id' => $unit->feed_id, 'field' => $unit->field,
                'source_locale' => $unit->source_locale, 'target_locale' => $unit->target_locale,
                'items' => $half, 'depth' => $unit->depth + 1,
            ]);
        }
        $this->finish($unit, 'split');
    }

    private function hold(WorkUnit $unit, string $reason): void
    {
        $unit->forceFill(['status' => 'held', 'hold_reason' => $reason, 'lease_token' => null, 'lease_until' => null])->save();
        RunProgress::refresh($unit->run_id);
        // The slot is free again: the next units get their turn (and are held too if the cause remains).
        $this->dispatcher->pump($unit->tenant_id);
    }

    private function later(WorkUnit $unit, int $seconds): void
    {
        $unit->forceFill(['status' => 'pending', 'lease_token' => null, 'lease_until' => null, 'available_at' => now()->addSeconds($seconds)])->save();
        $this->dispatcher->pump($unit->tenant_id);
    }

    private function finish(WorkUnit $unit, string $status, ?string $reason = null): void
    {
        $unit->forceFill(['status' => $status, 'failure_reason' => $reason, 'lease_token' => null, 'lease_until' => null])->save();
        RunProgress::refresh($unit->run_id);
        $this->dispatcher->pump($unit->tenant_id);
    }
}
