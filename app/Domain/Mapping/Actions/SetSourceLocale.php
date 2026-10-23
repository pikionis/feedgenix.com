<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Actions;

use App\Domain\Catalog\Schema\CanonicalSchema;
use App\Domain\Catalog\Schema\FieldRef;
use App\Domain\Mapping\Data\MappingInput;
use App\Domain\Mapping\Exceptions\MappingChanged;
use App\Domain\Mapping\Heuristics;
use App\Domain\Mapping\Models\Mapping;
use Illuminate\Support\Facades\DB;

/**
 * A person says which language the feed is written in. Short product names rarely give it away,
 * and the text fields cannot be placed without it. Fields that were filed under the old feed
 * language (not set by a person) move to the new one, and the draft's text fields are suggested
 * again in it; everything still goes through the pipeline.
 */
final readonly class SetSourceLocale
{
    public function __construct(
        private StoreDecisions $store,
        private CanonicalSchema $schema,
    ) {}

    /** @throws MappingChanged */
    public function handle(Mapping $mapping, int $version, string $locale): Mapping
    {
        if ($mapping->version !== $version) {
            throw new MappingChanged($mapping->version);
        }
        $locale = FieldRef::locale($locale);
        $previous = $mapping->source_locale;
        if ($previous === $locale) {
            return $mapping;
        }

        DB::transaction(static function () use ($mapping, $previous, $locale): void {
            if ($previous !== null) {
                $taken = $mapping->fields()->where('locale', $locale)->pluck('target')->all();
                $moving = $mapping->fields()->where('locale', $previous)->where('origin', '<>', 'manual');
                // A field already set in the new language keeps that row; the old one goes.
                (clone $moving)->whereIn('target', $taken)->delete();
                $moving->update(['locale' => $locale]);
            }
            $mapping->forceFill(['source_locale' => $locale])->save();
        });

        $input = MappingInput::fromImport(StoreDecisions::profileOf($mapping));

        return $this->store->handle($mapping->refresh(), (new Heuristics($this->schema))->propose($input, $locale));
    }
}
