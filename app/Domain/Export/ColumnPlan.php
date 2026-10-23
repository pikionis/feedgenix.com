<?php

declare(strict_types=1);

namespace App\Domain\Export;

use App\Domain\Catalog\Schema\CanonicalSchema;
use App\Domain\Catalog\Schema\FieldClass;
use App\Domain\Export\Data\ExportColumn;
use App\Domain\Export\Models\ExportProfile;
use InvalidArgumentException;

/**
 * The columns of each file a profile writes. Only fields the feed fills are written: an empty
 * column can wipe a channel's own data when it merges feeds (a legacy lesson), so a preset column
 * whose field the mapping does not fill is left out, unless the channel requires it. A text field
 * is written in the file's language (one file per language), or once per language with the
 * language in the header ("title_de-CH", the wide layout).
 */
final readonly class ColumnPlan
{
    public function __construct(private CanonicalSchema $schema) {}

    /**
     * @param  list<string>  $filled  the fields the feed's mapping fills
     * @return list<ExportColumn>
     */
    public function columns(ExportProfile $profile, array $filled, ?string $fileLocale): array
    {
        $preset = self::preset($profile->preset);
        $specs = is_array($preset['columns'] ?? null) ? $preset['columns'] : $this->standard($filled);
        $columns = [];
        foreach ($specs as $spec) {
            $field = (string) $spec['field'];
            $required = (bool) ($spec['required'] ?? false);
            if (! $this->schema->has($field) || (! $required && ! in_array($field, $filled, true))) {
                continue;
            }
            $definition = $this->schema->get($field);
            $transform = isset($spec['transform']) ? (string) $spec['transform'] : null;
            $exact = $definition->isGolden();
            if ($transform !== null && ! in_array($transform, $exact ? Transforms::GOLDEN : Transforms::TEXT, true)) {
                throw new InvalidArgumentException("The preset [{$profile->preset}] would {$transform} the golden field [{$field}].");
            }
            $header = (string) $spec['header'];
            if (! $definition->isLocalized()) {
                $columns[] = new ExportColumn($header, $field, null, $transform, $required, $exact);

                continue;
            }
            foreach ($fileLocale !== null ? [$fileLocale] : $profile->locales as $locale) {
                $columns[] = new ExportColumn($fileLocale !== null ? $header : "{$header}_{$locale}", $field, $locale, $transform, $required, false);
            }
        }

        return $columns;
    }

    /** @return array{label: string, dialects: list<string>, layouts: list<string>, columns: list<array<string, mixed>>|null} */
    public static function preset(string $key): array
    {
        $preset = config("export.presets.{$key}");
        if (! is_array($preset)) {
            throw new InvalidArgumentException("[{$key}] is not an export preset.");
        }

        /** @var array{label: string, dialects: list<string>, layouts: list<string>, columns: list<array<string, mixed>>|null} $preset */
        return $preset;
    }

    /**
     * Every field the feed fills, by its key: the SKU first, then the other golden and the
     * pass-through fields, then the texts, in the schema's order.
     *
     * @param  list<string>  $filled
     * @return list<array{header: string, field: string}>
     */
    private function standard(array $filled): array
    {
        $order = [
            ...array_keys($this->schema->ofClass(FieldClass::Golden)),
            ...array_keys($this->schema->ofClass(FieldClass::PassThrough)),
            ...array_keys($this->schema->ofClass(FieldClass::Translatable, FieldClass::Composable)),
        ];
        $order = ['sku', ...array_values(array_diff($order, ['sku']))];

        return array_values(array_map(
            static fn (string $field): array => ['header' => $field, 'field' => $field],
            array_filter($order, static fn (string $field): bool => $field === 'sku' || in_array($field, $filled, true)),
        ));
    }
}
