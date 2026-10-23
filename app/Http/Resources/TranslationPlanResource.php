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
            'saved' => $plan->exists,
            'fields' => $sources->map(static fn ($group, $field): array => [
                'field' => (string) $field,
                'label' => $schema->has((string) $field) ? $schema->get((string) $field)->label : (string) $field,
                /** @var 'translate'|'keep'|'skip' */
                'mode' => (string) ($plan->fields[(string) $field] ?? 'translate'),
                'sources' => $group->map(static fn (array $source): array => ['locale' => $source['locale'], 'products' => $source['products']])->values()->all(),
            ])->values()->all(),
            /** @var list<string> */
            'target_locales' => $plan->target_locales,
            'workspace_locales' => TenantLanguage::query()->orderBy('language_code')->pluck('language_code')
                ->map(static fn (string $code): array => ['code' => $code, 'name' => (string) ($names[$code] ?? $code)])->values()->all(),
            'work' => collect($work)->map(static fn (array $line): array => [
                'field' => $line['field'],
                'target_locale' => $line['target_locale'],
                /** @var 'translate'|'keep' */
                'mode' => $line['mode'],
                /** Texts with no fresh translation yet. */
                'texts' => $line['texts'],
                /** Of those, repeats the translation memory covers at no cost. */
                'memory' => $line['memory'],
            ])->values()->all(),
            'estimate' => [
                'available' => $estimate['available'],
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
