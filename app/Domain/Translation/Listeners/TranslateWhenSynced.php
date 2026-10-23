<?php

declare(strict_types=1);

namespace App\Domain\Translation\Listeners;

use App\Domain\Catalog\Events\CatalogSynced;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Translation\Actions\StartTranslation;
use App\Domain\Translation\Models\TranslationPlan;

/**
 * A plan that carries on by itself (ADR 0021): once a new file is read, its new and changed texts
 * are translated as the saved plan says, through the same start a person presses. Credits, the
 * allowance and AI availability hold the work exactly as they would then. A plan nobody saved, or
 * one without languages, waits for a person.
 */
final readonly class TranslateWhenSynced
{
    public function __construct(private StartTranslation $start) {}

    public function handle(CatalogSynced $event): void
    {
        $plan = TranslationPlan::query()->where('feed_id', $event->feedId)->first();
        $feed = Feed::query()->find($event->feedId);
        if (! $plan instanceof TranslationPlan || ! $plan->auto_translate || $plan->target_locales === [] || ! $feed instanceof Feed) {
            return;
        }

        $this->start->handle($feed, $plan->version);
    }
}
