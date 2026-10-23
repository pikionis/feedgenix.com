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
 * once at no cost; everything else is cut into work units (one field, one source and one target
 * language, a bounded batch), counted against the allowance, and handed to the dispatcher. The
 * plan that was started is saved as it was shown.
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

        $counts = ['kept' => 0, 'memory' => 0, 'planned' => 0, 'units' => 0];
        $products = [];
        $preferred = $this->planner->preferredSource($feed);
        foreach ($plan->fields as $field => $mode) {
            if ($mode === 'skip') {
                continue;
            }
            foreach ($plan->target_locales as $target) {
                $this->plan($run, $feed, (string) $field, (string) $mode, (string) $target, $preferred, $counts, $products);
            }
        }

        $admission = $this->admission->admit(Tenant::query()->findOrFail($feed->tenant_id), array_keys($products));
        $run->forceFill([
            'counts' => [...$counts, 'products' => count($products), 'over_allowance' => $admission['over']],
            'status' => $admission['held'] ? 'held' : ($counts['units'] === 0 ? 'done' : 'running'),
            'stage' => $counts['units'] === 0 && ! $admission['held'] ? 'done' : 'translate',
            'finished_at' => $counts['units'] === 0 && ! $admission['held'] ? now() : null,
            'warnings' => $admission['held'] ? [[
                'code' => 'allowance',
                'message' => "{$admission['over']} products are over this period's allowance. Upgrade, or the translation waits until ".substr($admission['resets_at'], 0, 10).'.',
                'count' => $admission['over'],
            ]] : [],
        ])->save();
        if ($admission['held']) {
            WorkUnit::query()->where('run_id', $run->id)->where('status', 'pending')->update(['status' => 'held', 'hold_reason' => 'allowance']);
        } else {
            $this->dispatcher->pump($feed->tenant_id);
        }
        // Kept texts and the memory's repeats were written at once.
        Feed::contentChanged($feed->id);

        return $run->refresh();
    }

    /**
     * @param  array<string, int>  $counts
     * @param  array<int, true>  $products
     */
    private function plan(PipelineRun $run, Feed $feed, string $field, string $mode, string $target, ?string $preferred, array &$counts, array &$products): void
    {
        /** @var array<string, array{items: array<int|string, string>, bytes: int}> $batches by source language; items: product id => source hash */
        $batches = [];
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

            $batches[$source] ??= ['items' => [], 'bytes' => 0];
            $batches[$source]['items'][(string) $productId] = $hash;
            $batches[$source]['bytes'] += strlen($text);
            $products[$productId] = true;
            $counts['planned']++;
            if (count($batches[$source]['items']) >= UnitSizing::maxItems($field) || $batches[$source]['bytes'] >= UnitSizing::MAX_BYTES) {
                $this->unit($run, $feed, $field, $source, $target, $batches[$source]['items'], $counts);
                $batches[$source] = ['items' => [], 'bytes' => 0];
            }
        }
        foreach ($batches as $source => $batch) {
            if ($batch['items'] !== []) {
                $this->unit($run, $feed, $field, (string) $source, $target, $batch['items'], $counts);
            }
        }
    }

    /**
     * @param  array<int|string, string>  $items  product id => source hash
     * @param  array<string, int>  $counts
     */
    private function unit(PipelineRun $run, Feed $feed, string $field, string $source, string $target, array $items, array &$counts): void
    {
        WorkUnit::query()->forceCreate([
            'run_id' => $run->id,
            'feed_id' => $feed->id,
            'field' => $field,
            'source_locale' => $source,
            'target_locale' => $target,
            'items' => $items,
        ]);
        $counts['units']++;
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
