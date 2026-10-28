<?php

declare(strict_types=1);

namespace App\Domain\Billing\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** The plan paused or resumed feeds or languages of a workspace (ids of feeds and tenant_languages). */
final readonly class PlanPausesChanged
{
    use Dispatchable;

    /**
     * @param  list<int>  $feedsPaused
     * @param  list<int>  $feedsResumed
     * @param  list<int>  $languagesPaused
     * @param  list<int>  $languagesResumed
     */
    public function __construct(
        public int $tenantId,
        public array $feedsPaused,
        public array $feedsResumed,
        public array $languagesPaused,
        public array $languagesResumed,
    ) {}
}
