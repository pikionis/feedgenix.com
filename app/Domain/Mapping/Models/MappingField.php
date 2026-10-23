<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Models;

use App\Domain\Mapping\Data\Decision;
use App\Domain\Mapping\Data\Recipe;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * One canonical field (in one locale) of a mapping, as the pipeline decided it.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $mapping_id
 * @property string $target
 * @property string|null $locale
 * @property string $strategy direct, compose or none
 * @property string|null $source_column
 * @property array{sources: list<array{column: string, label: string}>, separator: string, label_separator: string}|null $recipe
 * @property string|null $candidate_column
 * @property string $origin heuristic, ai or manual
 * @property string $confidence
 * @property string|null $reason
 * @property string $status validated, mapped, needs_input, composed, rejected or cleared
 * @property list<array{type: string, severity: string, count: int, message: string}> $issues
 */
class MappingField extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'locale' => null,
        'source_column' => null,
        'recipe' => null,
        'candidate_column' => null,
        'reason' => null,
        'issues' => '[]',
    ];

    /** @return array<string, mixed> the row for a decision */
    public static function attributesFor(Decision $decision, int $mappingId): array
    {
        return [
            'mapping_id' => $mappingId,
            'target' => $decision->target,
            'locale' => $decision->locale,
            'strategy' => $decision->strategy,
            'source_column' => $decision->column,
            'recipe' => $decision->recipe instanceof Recipe ? $decision->recipe->toArray() : null,
            'candidate_column' => $decision->candidate,
            'origin' => $decision->origin,
            'confidence' => number_format(max(0.0, min(1.0, $decision->confidence)), 3, '.', ''),
            'reason' => $decision->reason === null ? null : mb_substr($decision->reason, 0, 500),
            'status' => $decision->status,
            'issues' => $decision->issues,
        ];
    }

    public function key(): string
    {
        return $this->locale === null ? $this->target : "{$this->target}@{$this->locale}";
    }

    public function recipeObject(): ?Recipe
    {
        return $this->recipe === null ? null : Recipe::fromArray($this->recipe);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'mapping_id' => 'integer',
            'recipe' => 'array',
            'issues' => 'array',
        ];
    }
}
