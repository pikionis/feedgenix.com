<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Actions;

use App\Domain\Catalog\Schema\CanonicalSchema;
use App\Domain\Catalog\Schema\FieldRef;
use App\Domain\Mapping\Data\MappingInput;
use App\Domain\Mapping\Data\Proposal;
use App\Domain\Mapping\Data\Recipe;
use App\Domain\Mapping\Exceptions\MappingChanged;
use App\Domain\Mapping\Models\Mapping;
use App\Domain\Mapping\Models\MappingField;
use InvalidArgumentException;

/**
 * A person sets one field: to a column, to a composition, or to empty. It goes through the same
 * pipeline as every suggestion (legacy D7), so a protected column still cannot fill a text field,
 * and values that clearly do not fit are still refused, with the reason shown. The version guards
 * against two people editing the same mapping at once.
 */
final readonly class EditMappingField
{
    public function __construct(
        private StoreDecisions $store,
        private CanonicalSchema $schema,
    ) {}

    /**
     * @throws MappingChanged
     * @throws InvalidArgumentException for a field, locale or column that does not exist
     */
    public function handle(Mapping $mapping, int $version, string $target, ?string $locale, ?string $column, ?Recipe $recipe): MappingField
    {
        if ($mapping->version !== $version) {
            throw new MappingChanged($mapping->version);
        }
        if (! $this->schema->has($target) || ! $this->schema->get($target)->class->isMappable()) {
            throw new InvalidArgumentException("[{$target}] is not a field a feed column can fill.");
        }
        $field = $this->schema->get($target);
        $locale = FieldRef::of($this->schema, $target, $field->isLocalized() ? $locale : null)->locale;

        $input = MappingInput::fromImport(StoreDecisions::profileOf($mapping));
        foreach ($recipe instanceof Recipe ? $recipe->columns() : array_filter([$column]) as $name) {
            if (! $input->has($name)) {
                throw new InvalidArgumentException("The feed has no column [{$name}].");
            }
        }

        $proposal = match (true) {
            $recipe instanceof Recipe => Proposal::composed($target, $locale, $recipe, 1.0, 'Set by you.', 'manual'),
            $column !== null => Proposal::direct($target, $locale, $column, 1.0, 'Set by you.', 'manual'),
            default => Proposal::cleared($target, $locale),
        };

        $key = $proposal->key();

        return $this->store->handle($mapping, [$proposal])->fields()->get()
            ->first(static fn (MappingField $stored): bool => $stored->key() === $key)
            ?? throw new InvalidArgumentException("[{$key}] could not be set.");
    }
}
