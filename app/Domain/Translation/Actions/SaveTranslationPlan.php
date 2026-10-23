<?php

declare(strict_types=1);

namespace App\Domain\Translation\Actions;

use App\Domain\Feeds\Models\Feed;
use App\Domain\Tenancy\Models\TenantLanguage;
use App\Domain\Translation\Exceptions\PlanChanged;
use App\Domain\Translation\Models\TranslationPlan;
use App\Domain\Translation\TranslationPlanner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Saves a feed's choices: the mode of each text field it has, and the target languages, which
 * must be the workspace's own (the plan's language limit applies there). The version guards
 * against two people deciding at once.
 */
final readonly class SaveTranslationPlan
{
    public function __construct(private TranslationPlanner $planner) {}

    /**
     * @param  array<string, string>  $fields  field => translate, keep or skip
     * @param  list<string>  $targets
     *
     * @throws PlanChanged on a stale version
     * @throws InvalidArgumentException for an unknown field, mode or language
     */
    public function handle(Feed $feed, int $version, array $fields, array $targets, User $by): TranslationPlan
    {
        $known = array_unique(array_column($this->planner->sources($feed), 'field'));
        foreach ($fields as $field => $mode) {
            if (! in_array($field, $known, true)) {
                throw new InvalidArgumentException("The feed has no text for [{$field}].");
            }
            if (! in_array($mode, TranslationPlan::MODES, true)) {
                throw new InvalidArgumentException("[{$mode}] is not translate, keep or skip.");
            }
        }
        $languages = TenantLanguage::query()->pluck('language_code')->all();
        foreach ($targets as $target) {
            if (! in_array($target, $languages, true)) {
                throw new InvalidArgumentException("[{$target}] is not one of the workspace's languages: add it in the settings first.");
            }
        }

        return DB::transaction(function () use ($feed, $version, $fields, $targets, $by): TranslationPlan {
            $plan = TranslationPlan::query()->where('feed_id', $feed->id)->lockForUpdate()->first()
                ?? $this->planner->planOf($feed);
            if ($plan->version !== $version) {
                throw new PlanChanged($plan->version);
            }
            $plan->forceFill([
                'fields' => [...$plan->fields, ...$fields],
                'target_locales' => array_values(array_unique($targets)),
                'version' => $plan->exists ? $plan->version + 1 : 2,
                'updated_by' => $by->id,
            ])->save();

            return $plan;
        });
    }
}
