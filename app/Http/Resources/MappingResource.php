<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\AI\Exceptions\AiException;
use App\Domain\Catalog\Schema\CanonicalField;
use App\Domain\Catalog\Schema\CanonicalSchema;
use App\Domain\Catalog\Schema\FieldClass;
use App\Domain\Catalog\Schema\FieldKind;
use App\Domain\Credits\Millicredits;
use App\Domain\Ingest\Profile\ValueKind;
use App\Domain\Mapping\Actions\StoreDecisions;
use App\Domain\Mapping\Actions\SuggestMapping;
use App\Domain\Mapping\Data\MappingInput;
use App\Domain\Mapping\Data\Recipe;
use App\Domain\Mapping\Models\Mapping;
use App\Domain\Mapping\Models\MappingField;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A feed's mapping for review: every decided field with its source, status, the reason a reviewer
 * reads and sample values; the feed's columns; the canonical fields a column can fill; and what an
 * AI suggestion would cost at most, in credits (never a provider cost).
 *
 * @property Mapping $resource
 */
final class MappingResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = 'mapping';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $mapping = $this->resource;
        $schema = resolve(CanonicalSchema::class);
        $input = MappingInput::fromImport(StoreDecisions::profileOf($mapping));

        return [
            'id' => $mapping->id,
            'feed_id' => $mapping->feed_id,
            /** @var 'draft'|'approved' */
            'status' => $mapping->status,
            /** Send it back with every change: an edit on an older version is refused (409). */
            'version' => $mapping->version,
            'source_locale' => $mapping->source_locale,
            'approved_at' => $mapping->approved_at?->toIso8601String(),
            'ai' => $this->ai($mapping),
            'columns' => array_map(static fn (array $column): array => [
                'position' => $column['position'],
                'name' => $column['name'],
                /** @var ValueKind */
                'kind' => $column['kind'],
                'fill_rate' => $column['fill_rate'],
                /** Prices, stock, ids, images, weights: never shown to the AI, never in a text field. */
                'protected' => $column['protected'],
                'examples' => $column['examples'],
            ], $input->columns),
            'fields' => $mapping->fields()->orderBy('id')->get()->map(static fn (MappingField $field): array => self::field($schema, $input, $field))->values()->all(),
            'available_fields' => array_values(array_map(static fn (CanonicalField $field): array => [
                'key' => $field->key,
                'label' => $field->label,
                /** @var FieldClass */
                'class' => $field->class,
                /** @var FieldKind */
                'kind' => $field->kind,
                'description' => $field->description,
                'localized' => $field->isLocalized(),
                'composable' => $field->isComposable(),
            ], $schema->mappable())),
        ];
    }

    /** @return array<string, mixed> */
    private static function field(CanonicalSchema $schema, MappingInput $input, MappingField $field): array
    {
        $recipe = $field->recipeObject();
        $samples = match ($field->strategy) {
            'direct' => $input->values((string) $field->source_column),
            'compose' => $recipe instanceof Recipe ? $input->composed($recipe) : [],
            default => [],
        };

        return [
            'key' => $field->key(),
            'target' => $field->target,
            'label' => $schema->has($field->target) ? $schema->get($field->target)->label : $field->target,
            'locale' => $field->locale,
            /** @var 'direct'|'compose'|'none' */
            'strategy' => $field->strategy,
            'source_column' => $field->source_column,
            'recipe' => $recipe?->toArray(),
            /** What was suggested and refused, when the field was left empty. */
            'candidate_column' => $field->candidate_column,
            /** @var 'heuristic'|'ai'|'manual' */
            'origin' => $field->origin,
            'confidence' => (float) $field->confidence,
            'reason' => $field->reason,
            /** @var 'validated'|'mapped'|'needs_input'|'composed'|'rejected'|'cleared' */
            'status' => $field->status,
            /** @var list<array{type: string, severity: 'error'|'warning'|'info', count: int, message: string}> */
            'issues' => $field->issues,
            /** @var list<string> */
            'samples' => array_slice(array_map(static fn (string $value): string => mb_substr($value, 0, 300), $samples), 0, 5),
        ];
    }

    /** @return array{status: 'none'|'queued'|'running'|'done'|'failed', failure: string|null, available: bool, unavailable_reason: string|null, estimate_credits: int|null} */
    private function ai(Mapping $mapping): array
    {
        $estimate = null;
        $reason = null;
        try {
            $estimate = Millicredits::toCredits(resolve(SuggestMapping::class)->worstCase($mapping));
        } catch (AiException $unavailable) {
            $reason = $unavailable->reason;
        }

        /** @var 'none'|'queued'|'running'|'done'|'failed' $status */
        $status = $mapping->ai_status;

        return [
            'status' => $status,
            'failure' => $mapping->ai_failure,
            'available' => $estimate !== null,
            /** e.g. ai_disabled, unpriced_model, provider_unavailable */
            'unavailable_reason' => $reason,
            /** The most a suggestion can cost, in credits; usually far less is used. */
            'estimate_credits' => $estimate,
        ];
    }
}
