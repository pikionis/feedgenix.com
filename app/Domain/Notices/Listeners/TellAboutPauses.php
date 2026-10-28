<?php

declare(strict_types=1);

namespace App\Domain\Notices\Listeners;

use App\Domain\Billing\Entitlements;
use App\Domain\Billing\Events\PlanPausesChanged;
use App\Domain\Billing\PlanCatalog;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Notices\Notify;
use App\Domain\Tenancy\Models\TenantLanguage;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Translation\Models\Language;

/**
 * Tells a workspace what its plan paused (by email: it needs a decision) or resumed (in the app),
 * by name, and that nothing is deleted (ADR 0024, ADR 0025).
 */
final readonly class TellAboutPauses
{
    public function __construct(private TenantContext $context) {}

    public function handle(PlanPausesChanged $event): void
    {
        $this->context->run($event->tenantId, function () use ($event): void {
            $plan = PlanCatalog::name(Entitlements::for($event->tenantId)->plan());
            $at = now()->format('YmdHis');
            if ($event->feedsPaused !== [] || $event->languagesPaused !== []) {
                Notify::send($event->tenantId, 'plan', "plan_paused:{$at}", 'action', 'Your plan paused '.$this->what($event->feedsPaused, $event->languagesPaused),
                    "Your workspace is on {$plan}. Paused: ".$this->names($event->feedsPaused, $event->languagesPaused).'. Nothing is deleted: paused feeds keep serving their feed URLs and take no new files; paused languages get no new translations. Upgrade, or remove others, to resume them.',
                    'See the plans', '/app/settings', email: true);
            }
            if ($event->feedsResumed !== [] || $event->languagesResumed !== []) {
                Notify::send($event->tenantId, 'plan', "plan_resumed:{$at}", 'info', 'Resumed '.$this->what($event->feedsResumed, $event->languagesResumed),
                    'Active again: '.$this->names($event->feedsResumed, $event->languagesResumed).'. Their waiting work continues.');
            }
        });
    }

    /**
     * @param  list<int>  $feeds
     * @param  list<int>  $languages
     */
    private function what(array $feeds, array $languages): string
    {
        $parts = array_filter([
            $feeds === [] ? null : count($feeds).' '.(count($feeds) === 1 ? 'feed' : 'feeds'),
            $languages === [] ? null : count($languages).' '.(count($languages) === 1 ? 'language' : 'languages'),
        ]);

        return implode(' and ', $parts);
    }

    /**
     * @param  list<int>  $feeds
     * @param  list<int>  $languages
     */
    private function names(array $feeds, array $languages): string
    {
        $codes = TenantLanguage::query()->whereKey($languages)->orderBy('language_code')->pluck('language_code')->all();
        $names = Language::query()->whereIn('code', $codes)->pluck('name', 'code');

        return implode('; ', array_filter([
            $feeds === [] ? null : 'feeds '.implode(', ', Feed::query()->whereKey($feeds)->orderBy('name')->pluck('name')->all()),
            $codes === [] ? null : 'languages '.implode(', ', array_map(static fn (string $code): string => (string) ($names[$code] ?? $code), $codes)),
        ]));
    }
}
