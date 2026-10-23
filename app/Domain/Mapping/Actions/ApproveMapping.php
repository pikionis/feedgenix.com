<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Actions;

use App\Domain\Mapping\Events\MappingApproved;
use App\Domain\Mapping\Exceptions\MappingChanged;
use App\Domain\Mapping\Exceptions\MappingIncomplete;
use App\Domain\Mapping\Models\Mapping;
use App\Domain\Mapping\Models\MappingField;
use App\Models\User;

/**
 * A person confirms the mapping. It needs the SKU (products are matched on it) and at least one
 * text field to work on; everything else may stay empty. Approving an older version is refused.
 */
final class ApproveMapping
{
    /**
     * @throws MappingChanged
     * @throws MappingIncomplete
     */
    public function handle(Mapping $mapping, int $version, User $by): Mapping
    {
        if ($mapping->version !== $version) {
            throw new MappingChanged($mapping->version);
        }

        $filled = $mapping->fields()->get()->filter(static fn (MappingField $field): bool => $field->strategy !== 'none');
        if ($filled->doesntContain(static fn (MappingField $field): bool => $field->target === 'sku')) {
            throw new MappingIncomplete('sku_missing', 'Choose the column that holds the SKU first: products are matched on it.');
        }
        if ($filled->doesntContain(static fn (MappingField $field): bool => $field->locale !== null)) {
            throw new MappingIncomplete('no_text', 'Map at least one text field (a title or a description) to work on.');
        }

        $mapping->forceFill(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now()])->save();
        event(new MappingApproved($mapping->id));

        return $mapping;
    }
}
