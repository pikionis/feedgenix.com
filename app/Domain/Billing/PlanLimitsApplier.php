<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Events\PlanPausesChanged;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Tenancy\Models\TenantLanguage;
use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Brings a workspace in line with the plan in force (ported from the legacy PlanLimitsApplier):
 * after a plan change, a lapsed plan, or a feed or language removed. Nothing is ever deleted.
 *
 *  - Feeds over the limit pause, the least recently changed first: no new files and no AI work,
 *    and their exports keep serving the last build. When the limit grows, paused feeds resume,
 *    the most recently changed first. A workspace's own choice is kept whenever it fits.
 *  - Languages over the limit pause, the last added first: nothing new is translated into them.
 *    (The legacy app paused feeds only, so a downgrade kept every language translating.)
 *  - What paused or resumed is announced (PlanPausesChanged): the translation holds the waiting
 *    work of what paused and hands it out again when it resumes (the legacy app let queued work
 *    run on).
 */
final readonly class PlanLimitsApplier
{
    public function __construct(private TenantContext $context) {}

    /** @return array{plan: string, feeds_paused: int, feeds_resumed: int, languages_paused: int, languages_resumed: int} */
    public function apply(Tenant $tenant): array
    {
        $entitlements = Entitlements::for($tenant);

        $result = $this->context->run($tenant->id, fn (): array => DB::transaction(function () use ($entitlements): array {
            [$feedsPaused, $feedsResumed] = $this->fit(
                array_values(Feed::query()->get(['id', 'plan_paused_at', 'updated_at'])->map(static fn (Feed $feed): array => ['id' => $feed->id, 'paused' => $feed->plan_paused_at !== null, 'at' => (string) $feed->updated_at?->toIso8601String()])->all()),
                $entitlements->limit('feeds'),
                // Keep the most recently changed, pause the others; resume the most recent first.
                keepNewest: true,
            );
            [$languagesPaused, $languagesResumed] = $this->fit(
                array_values(TenantLanguage::query()->get(['id', 'plan_paused_at', 'created_at'])->map(static fn (TenantLanguage $language): array => ['id' => $language->id, 'paused' => $language->plan_paused_at !== null, 'at' => (string) $language->created_at?->toIso8601String()])->all()),
                $entitlements->limit('languages'),
                // Keep the first added, pause the last added; resume in the order they were added.
                keepNewest: false,
            );

            $this->pause(Feed::query(), $feedsPaused, $feedsResumed);
            $this->pause(TenantLanguage::query(), $languagesPaused, $languagesResumed);

            return [$feedsPaused, $feedsResumed, $languagesPaused, $languagesResumed];
        }));
        $tenant->forceFill(['applied_plan' => $entitlements->plan()])->save();
        if (array_filter($result) !== []) {
            event(new PlanPausesChanged($tenant->id, ...$result));
        }

        return ['plan' => $entitlements->plan(), 'feeds_paused' => count($result[0]), 'feeds_resumed' => count($result[1]), 'languages_paused' => count($result[2]), 'languages_resumed' => count($result[3])];
    }

    /**
     * Which of the items to pause and which to resume so that no more than $limit are active.
     *
     * @param  list<array{id: int, paused: bool, at: string}>  $items
     * @return array{0: list<int>, 1: list<int>} ids to pause, ids to resume
     */
    private function fit(array $items, ?int $limit, bool $keepNewest): array
    {
        $byAge = static fn (array $a, array $b): int => [$a['at'], $a['id']] <=> [$b['at'], $b['id']];
        $active = array_values(array_filter($items, static fn (array $item): bool => ! $item['paused']));
        $paused = array_values(array_filter($items, static fn (array $item): bool => $item['paused']));
        usort($active, $byAge);
        usort($paused, $byAge);
        if ($keepNewest) {
            // The oldest active pause first; the newest paused resume first.
            $paused = array_reverse($paused);
        } else {
            // The newest active pause first; the oldest paused resume first.
            $active = array_reverse($active);
        }

        $pause = $limit !== null && count($active) > $limit ? array_slice($active, 0, count($active) - $limit) : [];
        $room = $limit === null ? count($paused) : max(0, $limit - count($active));

        return [array_column($pause, 'id'), array_column(array_slice($paused, 0, $room), 'id')];
    }

    /**
     * @param  Builder<Feed>|Builder<TenantLanguage>  $query
     * @param  list<int>  $pause
     * @param  list<int>  $resume
     */
    private function pause($query, array $pause, array $resume): void
    {
        // Without touching updated_at: a pause is not a change of the feed, and must not reorder them.
        if ($pause !== []) {
            (clone $query)->whereKey($pause)->toBase()->update(['plan_paused_at' => now()]);
        }
        if ($resume !== []) {
            (clone $query)->whereKey($resume)->toBase()->update(['plan_paused_at' => null]);
        }
    }
}
