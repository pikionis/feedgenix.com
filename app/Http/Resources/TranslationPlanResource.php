<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Catalog\Schema\CanonicalSchema;
use App\Domain\Feeds\Models\Feed;
use App\Domain\Feeds\Models\PipelineRun;
use App\Domain\Tenancy\Models\TenantLanguage;
use App\Domain\Translation\Models\Language;
use App\Domain\Translation\Models\TranslationPlan;
use App\Domain\Translation\TranslationEstimate;
use App\Domain\Translation\TranslationPlanner;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A feed's translation plan for review: each text field it has with its mode and source languages,
 * the target languages, the work per field and language, what the translation memory covers, and
 * the estimate in credits (never a provider cost). Built from the stored plan or the defaults.
 *
 * @property TranslationPlan $resource
 */
final class TranslationPlanResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = 'translation_plan';

    public function __construct(TranslationPlan $plan, private readonly Feed $feed)
    {
        parent::__construct($plan);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $plan = $this->resource;
        $planner = resolve(TranslationPlanner::class);
        $schema = resolve(CanonicalSchema::class);
        $sources = collect($planner->sources($this->feed))->groupBy('field');
        $work = $planner->work($this->feed, $plan);
        $estimate = resolve(TranslationEstimate::class)->of($work);
        $names = Language::query()->pluck('name', 'code');
        $run = PipelineRun::query()->where('feed_id', $this->feed->id)->orderByDesc('id')->first();

        return [
            /** Send it back with a change: a save on an older version is refused (409). */
            'version' => $plan->version,
            /** False until someone saves it: these are the defaults. */
            'saved' => (bool) $plan->exists,
            /**
             * Each text field the feed has, with the languages its products provide it in.
             *
             * @var list<array{field: string, label: string, mode: 'translate'|'keep'|'skip', sources: list<array{locale: string, products: int}>}>
             */
            'fields' => $sources->map(static fn ($group, $field): array => [
                'field' => (string) $field,
                'label' => $schema->has((string) $field) ? $schema->get((string) $field)->label : (string) $field,
                'mode' => (string) ($plan->fields[(string) $field] ?? 'translate'),
                'sources' => $group->map(static fn (array $source): array => ['locale' => $source['locale'], 'products' => $source['products']])->values()->all(),
            ])->values()->all(),
            /** @var list<string> */
            'target_locales' => $plan->target_locales,
            /** After each new file, its new and changed texts are translated without waiting for a person. */
            'auto_translate' => (bool) $plan->auto_translate,
            /**
             * The workspace's languages; a paused one (over the plan's limit) gets no new translations.
             *
             * @var list<array{code: string, name: string, paused: bool}>
             */
            'workspace_locales' => TenantLanguage::query()->orderBy('language_code')->get(['language_code', 'plan_paused_at'])
                ->map(static fn (TenantLanguage $language): array => ['code' => $language->language_code, 'name' => (string) ($names[$language->language_code] ?? $language->language_code), 'paused' => $language->plan_paused_at !== null])->values()->all(),
            /**
             * Per field and target language: the texts with no fresh translation yet, and how many
             * of those are repeats the translation memory covers at no cost.
             *
             * @var list<array{field: string, target_locale: string, mode: 'translate'|'keep', texts: int, memory: int}>
             */
            'work' => collect($work)->map(static fn (array $line): array => [
                'field' => $line['field'],
                'target_locale' => $line['target_locale'],
                'mode' => $line['mode'],
                'texts' => (int) $line['texts'],
                'memory' => (int) $line['memory'],
            ])->values()->all(),
            'estimate' => [
                'available' => (bool) $estimate['available'],
                /**
                 * Why there is no estimate: ai_disabled, unpriced_model, provider_unavailable.
                 *
                 * @var string|null
                 */
                'unavailable_reason' => $estimate['unavailable_reason'],
                /** @var int|null */
                'about_credits' => $estimate['about_credits'],
                /**
                 * The most it can cost; each call reserves its share first.
                 *
                 * @var int|null
                 */
                'at_most_credits' => $estimate['at_most_credits'],
            ],
            'run' => $run instanceof PipelineRun ? [
                'id' => $run->id,
                /** @var 'sync'|'plan'|'translate'|'export'|'done' */
                'stage' => $run->stage,
                /** @var 'queued'|'running'|'awaiting_decision'|'held'|'done'|'failed' */
                'status' => $run->status,
                /** @var array<string, int> */
                'counts' => $run->counts,
                /** @var list<array{code: string, message: string, count: int}> */
                'warnings' => $run->warnings,
                'failure_message' => $run->failure_message,
            ] : null,
        ];
    }
}
