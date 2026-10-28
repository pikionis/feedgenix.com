<?php

declare(strict_types=1);

namespace App\Domain\Translation\Actions;

use App\Domain\Feeds\Models\Feed;
use App\Domain\Feeds\Models\PipelineRun;
use App\Domain\Metering\Admission;
use App\Domain\Translation\Exceptions\PlanChanged;
use App\Domain\Translation\Models\TranslationMemory;
use App\Domain\Translation\Models\WorkUnit;
use App\Domain\Translation\TargetWriter;
use App\Domain\Translation\TranslationPlanner;
use App\Domain\Translation\UnitSizing;
use App\Domain\Translation\WorkDispatcher;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Turns the plan into work: "keep" fields and the translation memory's repeats are written at
 * once at no cost; everything else is cut into batches (one field, one source and one target
 * language, bounded), counted against the allowance, and made work units: the products admitted go
 * to the dispatcher, the ones over the allowance wait in units of their own (ADR 0027). The plan
 * that was started is saved as it was shown.
 */
final readonly class StartTranslation
{
    public function __construct(
        private TranslationPlanner $planner,
        private Admission $admission,
        private WorkDispatcher $dispatcher,
    ) {}

    /** @throws PlanChanged */
    public function handle(Feed $feed, int $version): PipelineRun
    {
        $plan = $this->planner->planOf($feed);
        if ($plan->version !== $version) {
            throw new PlanChanged($plan->version);
        }
        if (! $plan->exists) {
            $plan->save();
        }

        $running = PipelineRun::query()->where('feed_id', $feed->id)->whereIn('status', PipelineRun::UNFINISHED)->first();
        if ($running instanceof PipelineRun && $running->stage !== 'plan') {
            return $running; // already translating (or still syncing): one run at a time
        }
        $run = $running ?? $this->newRun($feed);
        $run->forceFill(['stage' => 'translate', 'status' => 'running', 'started_at' => $run->started_at ?? now()])->save();

        $counts = ['kept' => 0, 'memory' => 0, 'planned' => 0, 'units' => 0, 'units_waiting' => 0];
        $products = [];
        $batches = [];
        $preferred = $this->planner->preferredSource($feed);
        foreach ($plan->fields as $field => $mode) {
            if ($mode === 'skip') {
                continue;
            }
            foreach ($this->planner->targets($plan) as $target) {
                $this->plan($feed, (string) $field, (string) $mode, (string) $target, $preferred, $counts, $products, $batches);
            }
        }
        $admission = $this->admission->admit(Tenant::query()->findOrFail($feed->tenant_id), array_keys($products));
        $waiting = array_fill_keys($admission['waiting'], true);
        foreach ($batches as $batch) {
            // One batch, two units at most: what was admitted runs, what is over the allowance waits.
            $admitted = array_filter($batch['items'], static fn (string $hash, int|string $productId): bool => ! isset($waiting[(int) $productId]), ARRAY_FILTER_USE_BOTH);
            $held = array_diff_key($batch['items'], $admitted);
            if ($admitted !== []) {
                $this->unit($run, $feed, $batch, $admitted);
                $counts['units']++;
            }
            if ($held !== []) {
                $this->unit($run, $feed, $batch, $held, 'allowance');
                $counts['units_waiting']++;
            }
        }
        $finished = $counts['units'] === 0 && $counts['units_waiting'] === 0;
        $run->forceFill([
            'counts' => [...$counts, 'products' => count($products), 'over_allowance' => $admission['over']],
            'status' => $finished ? 'done' : ($counts['units'] === 0 ? 'held' : 'running'),
            'stage' => $finished ? 'done' : 'translate',
            'finished_at' => $finished ? now() : null,
            'warnings' => $admission['held'] ? [[
                'code' => 'allowance',
                'message' => count($admission['waiting']).' products are over this period\'s allowance: they wait until '.substr($admission['resets_at'], 0, 10).', or start at once when you upgrade.',
                'count' => count($admission['waiting']),
            ]] : [],
        ])->save();
        if ($counts['units'] > 0) {
            $this->dispatcher->pump($feed->tenant_id);
        }
        // Kept texts and the memory's repeats were written at once.
        Feed::contentChanged($feed->id);

        return $run->refresh();
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<int, true>  $products
     * @param  list<array{field: string, source: string, target: string, items: array<int|string, string>}>  $batches
     */
    private function plan(Feed $feed, string $field, string $mode, string $target, ?string $preferred, array &$counts, array &$products, array &$batches): void
    {
        /** @var array<string, array{items: array<int|string, string>, bytes: int}> $open by source language; items: product id => source hash */
        $open = [];
        foreach ($this->planner->pending($feed, $field, $target, $preferred) as $row) {
            $values = (array) $row;
            $productId = (int) $values['product_id'];
            $source = (string) $values['source_locale'];
            $hash = (string) $values['source_hash'];
            $text = (string) $values['value'];

            if ($mode === 'keep') {
                $counts['kept'] += (int) TargetWriter::write($feed->tenant_id, $productId, $field, $source, $hash, $target, $text, 'source', 'ready');

                continue;
            }
            if (is_string($values['memory'] ?? null)) {
                if (TargetWriter::write($feed->tenant_id, $productId, $field, $source, $hash, $target, $values['memory'], 'tm', 'ready')) {
                    $counts['memory']++;
                    TranslationMemory::query()->where('field', $field)->where('source_locale', $source)->where('target_locale', $target)->where('source_hash', $hash)
                        ->update(['hits' => DB::raw('hits + 1'), 'last_used_at' => now()]);
                }

                continue;
            }

            $open[$source] ??= ['items' => [], 'bytes' => 0];
            $open[$source]['items'][(string) $productId] = $hash;
            $open[$source]['bytes'] += strlen($text);
            $products[$productId] = true;
            $counts['planned']++;
            if (count($open[$source]['items']) >= UnitSizing::maxItems($field) || $open[$source]['bytes'] >= UnitSizing::MAX_BYTES) {
                $batches[] = ['field' => $field, 'source' => $source, 'target' => $target, 'items' => $open[$source]['items']];
                $open[$source] = ['items' => [], 'bytes' => 0];
            }
        }
        foreach ($open as $source => $batch) {
            if ($batch['items'] !== []) {
                $batches[] = ['field' => $field, 'source' => (string) $source, 'target' => $target, 'items' => $batch['items']];
            }
        }
    }

    /**
     * @param  array{field: string, source: string, target: string}  $batch
     * @param  array<int|string, string>  $items  product id => source hash
     */
    private function unit(PipelineRun $run, Feed $feed, array $batch, array $items, ?string $holdReason = null): void
    {
        WorkUnit::query()->forceCreate([
            'run_id' => $run->id,
            'feed_id' => $feed->id,
            'field' => $batch['field'],
            'source_locale' => $batch['source'],
            'target_locale' => $batch['target'],
            'items' => $items,
            'status' => $holdReason === null ? 'pending' : 'held',
            'hold_reason' => $holdReason,
        ]);
    }

    private function newRun(Feed $feed): PipelineRun
    {
        try {
            return DB::transaction(static fn (): PipelineRun => PipelineRun::query()->forceCreate(['feed_id' => $feed->id, 'stage' => 'translate', 'status' => 'queued']));
        } catch (UniqueConstraintViolationException) {
            return PipelineRun::query()->where('feed_id', $feed->id)->whereIn('status', PipelineRun::UNFINISHED)->firstOrFail();
        }
    }
}
