<?php

declare(strict_types=1);

namespace App\Domain\Translation\Listeners;

use App\Domain\Billing\Events\PlanPausesChanged;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Tenancy\Models\TenantLanguage;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Translation\Models\WorkUnit;
use App\Domain\Translation\RunProgress;
use App\Domain\Translation\WorkDispatcher;
use Illuminate\Database\Eloquent\Builder;

/**
 * Waiting AI work follows the plan's pauses (ADR 0024): the pending units of a paused feed or
 * into a paused language are held, and handed out again once nothing keeps them paused. A unit a
 * worker already holds finishes: its credits are reserved.
 */
final readonly class HoldPausedWork
{
    public function __construct(
        private TenantContext $context,
        private WorkDispatcher $dispatcher,
    ) {}

    public function handle(PlanPausesChanged $event): void
    {
        $this->context->run($event->tenantId, function () use ($event): void {
            $runs = [];
            if ($event->feedsPaused !== []) {
                $runs = [...$runs, ...$this->hold(WorkUnit::query()->whereIn('feed_id', $event->feedsPaused), 'feed_paused')];
            }
            if ($event->languagesPaused !== []) {
                $codes = TenantLanguage::query()->whereKey($event->languagesPaused)->pluck('language_code')->all();
                $runs = [...$runs, ...$this->hold(WorkUnit::query()->whereIn('target_locale', $codes), 'language_paused')];
            }
            if ($event->feedsResumed !== [] || $event->languagesResumed !== []) {
                $released = WorkUnit::query()->where('status', 'held')->whereIn('hold_reason', ['feed_paused', 'language_paused'])
                    ->whereNotIn('feed_id', Feed::query()->whereNotNull('plan_paused_at')->select('id'))
                    ->whereNotIn('target_locale', TenantLanguage::query()->whereNotNull('plan_paused_at')->select('language_code'));
                $runs = [...$runs, ...(clone $released)->pluck('run_id')->all()];
                $released->update(['status' => 'pending', 'hold_reason' => null]);
            }
            foreach (array_unique(array_map(intval(...), $runs)) as $runId) {
                RunProgress::refresh($runId);
            }
        });
        $this->dispatcher->pump($event->tenantId);
    }

    /**
     * @param  Builder<WorkUnit>  $units
     * @return list<mixed> the runs touched
     */
    private function hold($units, string $reason): array
    {
        $pending = $units->where('status', 'pending');
        $runs = array_values((clone $pending)->pluck('run_id')->all());
        $pending->update(['status' => 'held', 'hold_reason' => $reason]);

        return $runs;
    }
}
