<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Actions;

use App\Domain\Ingest\ImportStatus;
use App\Domain\Ingest\Models\Import;
use App\Domain\Mapping\Data\Decision;
use App\Domain\Mapping\Data\MappingInput;
use App\Domain\Mapping\Data\Proposal;
use App\Domain\Mapping\Data\Recipe;
use App\Domain\Mapping\MappingPipeline;
use App\Domain\Mapping\Models\Mapping;
use App\Domain\Mapping\Models\MappingField;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Adds proposals to a mapping and stores what the pipeline decides. The mapping's current fields
 * are judged again with them (a person's edits always win), so whatever path a change took, the
 * stored mapping is one the pipeline produced. Refused suggestions with no new proposal stay, so
 * the reviewer still sees what was refused and why. Any change sends an approved mapping back to
 * draft.
 */
final readonly class StoreDecisions
{
    public function __construct(private MappingPipeline $pipeline) {}

    /** @param list<Proposal> $proposals */
    public function handle(Mapping $mapping, array $proposals, bool $isChange = true): Mapping
    {
        $input = MappingInput::fromImport(self::profileOf($mapping));
        $existing = $mapping->fields()->get();

        $carried = [];
        $refused = [];
        foreach ($existing as $field) {
            $proposal = $this->asProposal($field);
            if ($proposal instanceof Proposal) {
                $carried[] = $proposal;
            } else {
                $refused[$field->key()] = $field;
            }
        }

        $decisions = $this->pipeline->decide($input, [...$carried, ...$proposals]);
        $decided = array_flip(array_map(static fn (Decision $decision): string => $decision->key(), $decisions));

        return DB::transaction(static function () use ($mapping, $decisions, $decided, $refused, $isChange): Mapping {
            $kept = array_filter($refused, static fn (MappingField $field): bool => ! isset($decided[$field->key()]));
            $mapping->fields()->whereNotIn('id', array_map(static fn (MappingField $field): int => $field->id, $kept))->delete();
            foreach ($decisions as $decision) {
                MappingField::query()->forceCreate(MappingField::attributesFor($decision, $mapping->id));
            }

            if ($isChange) {
                $mapping->forceFill([
                    'version' => $mapping->version + 1,
                    'status' => 'draft',
                    'approved_by' => null,
                    'approved_at' => null,
                ])->save();
            }

            return $mapping->refresh();
        });
    }

    /** The profile a mapping reads: its own import, or the latest with the same columns. */
    public static function profileOf(Mapping $mapping): Import
    {
        $import = $mapping->import_id === null ? null : Import::query()->find($mapping->import_id);

        return $import ?? Import::query()
            ->where('feed_id', $mapping->feed_id)
            ->where('column_signature', $mapping->column_signature)
            ->where('status', ImportStatus::Profiled->value)
            ->orderByDesc('id')
            ->first()
            ?? throw new RuntimeException('The mapping has no profiled import with its columns.');
    }

    private function asProposal(MappingField $field): ?Proposal
    {
        $confidence = (float) $field->confidence;

        return match (true) {
            $field->strategy === 'direct' && $field->source_column !== null => Proposal::direct($field->target, $field->locale, $field->source_column, $confidence, $field->reason, $field->origin),
            $field->strategy === 'compose' && $field->recipeObject() instanceof Recipe => Proposal::composed($field->target, $field->locale, $field->recipeObject(), $confidence, $field->reason, $field->origin),
            $field->status === 'cleared' => Proposal::cleared($field->target, $field->locale),
            default => null,
        };
    }
}
