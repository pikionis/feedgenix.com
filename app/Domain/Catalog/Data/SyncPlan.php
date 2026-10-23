<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

use App\Domain\Catalog\Schema\CanonicalSchema;
use App\Domain\Mapping\Data\Recipe;
use App\Domain\Mapping\Models\Mapping;
use App\Domain\Mapping\Models\MappingField;
use InvalidArgumentException;

/**
 * What an approved mapping says to read from each row: the SKU column, the columns of golden and
 * pass-through fields (copied byte for byte), and the column or composition of every text field
 * in its language.
 */
final readonly class SyncPlan
{
    /**
     * @param  array<string, string>  $values  field => column
     * @param  array<string, array{field: string, locale: string, column: ?string, recipe: ?Recipe}>  $texts  field@locale => source
     */
    public function __construct(
        public string $skuColumn,
        public array $values,
        public array $texts,
    ) {}

    public static function of(Mapping $mapping, CanonicalSchema $schema): self
    {
        $sku = null;
        $values = [];
        $texts = [];
        foreach ($mapping->fields()->where('strategy', '<>', 'none')->get() as $field) {
            /** @var MappingField $field */
            if (! $schema->has($field->target)) {
                continue;
            }
            if ($field->target === 'sku') {
                $sku = $field->source_column;

                continue;
            }
            $definition = $schema->get($field->target);
            if ($definition->isLocalized() && $field->locale !== null) {
                $texts[$field->key()] = [
                    'field' => $field->target,
                    'locale' => $field->locale,
                    'column' => $field->strategy === 'direct' ? $field->source_column : null,
                    'recipe' => $field->strategy === 'compose' ? $field->recipeObject() : null,
                ];
            } elseif ($field->strategy === 'direct' && $field->source_column !== null) {
                $values[$field->target] = $field->source_column;
            }
        }

        return new self($sku ?? throw new InvalidArgumentException('The mapping has no SKU column.'), $values, $texts);
    }
}
