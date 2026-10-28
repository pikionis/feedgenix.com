<?php

declare(strict_types=1);

namespace App\Domain\Translation;

use App\Domain\Feeds\Models\Feed;
use App\Domain\Feeds\Models\PipelineRun;
use App\Domain\Translation\Models\WorkUnit;

/**
 * Where a translating run stands, from its work units (the one place progress is counted; the
 * legacy app counted it in several places that disagreed). When no unit is pending or leased the
 * run is done, or held when some wait for credits, the allowance or AI.
 */
final class RunProgress
{
    public static function refresh(int $runId): void
    {
        $run = PipelineRun::query()->find($runId);
        if (! $run instanceof PipelineRun || $run->stage !== 'translate') {
            return;
        }

        // A split unit's texts are counted in its halves (the split unit's own status is "split").
        $rows = WorkUnit::query()->where('run_id', $runId)
            ->selectRaw('status, count(*) AS n, sum((SELECT count(*) FROM jsonb_object_keys(items))) AS texts')->groupBy('status')->get();
        $units = $rows->mapWithKeys(static fn (WorkUnit $row): array => [$row->status => (int) $row->getAttribute('n')])->all();
        $texts = $rows->mapWithKeys(static fn (WorkUnit $row): array => [$row->status => (int) $row->getAttribute('texts')])->all();
        $open = ($units['pending'] ?? 0) + ($units['leased'] ?? 0);
        $held = $units['held'] ?? 0;
        $holds = WorkUnit::query()->where('run_id', $runId)->where('status', 'held')->distinct()->pluck('hold_reason')->filter()->values()->all();

        $counts = [
            ...$run->counts,
            'units_done' => $units['done'] ?? 0, 'units_failed' => $units['failed'] ?? 0, 'units_held' => $held, 'units_open' => $open,
            'texts_done' => $texts['done'] ?? 0, 'texts_failed' => $texts['failed'] ?? 0, 'texts_held' => $texts['held'] ?? 0,
            'texts_open' => ($texts['pending'] ?? 0) + ($texts['leased'] ?? 0),
        ];
        $run->forceFill(match (true) {
            $open > 0 => ['status' => 'running', 'counts' => $counts],
            $held > 0 => ['status' => 'held', 'counts' => [...$counts, 'hold_reasons' => count($holds)], 'warnings' => array_map(static fn (string $reason): array => ['code' => "held_{$reason}", 'message' => self::holdMessage($reason), 'count' => $held], $holds)],
            default => ['status' => 'done', 'stage' => 'done', 'counts' => $counts, 'finished_at' => now()],
        })->save();
        if ($run->status === 'done') {
            Feed::contentChanged($run->feed_id);
        }
    }

    private static function holdMessage(string $reason): string
    {
        return match ($reason) {
            'credits' => 'Some texts wait for credits: the workspace does not hold enough for them.',
            'allowance' => 'Some products are over this period\'s allowance: upgrade, or they wait for the next period.',
            'ai_disabled', 'unpriced_model', 'provider_unavailable' => 'Some texts wait: AI is not available right now.',
            'ai_paused', 'workspace_frozen' => 'AI is paused for this workspace.',
            'feed_paused' => 'This feed is paused by your plan: its texts wait until it is active again.',
            'language_paused' => 'Some languages are paused by your plan: their texts wait until they are active again.',
            default => 'Some texts wait: they resume on their own when the cause clears.',
        };
    }
}
