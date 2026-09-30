<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Ingest\Models\Import;
use App\Domain\Ingest\Models\ImportColumn;
use App\Domain\Ingest\Profile\ValueKind;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An import in full: how the file was read (format, encoding, delimiter, XML item), its column
 * profile, sample rows, the suggested SKU column and the warnings. Never the stored file's path.
 *
 * @property Import $resource
 */
final class ImportResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = 'import';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $import = $this->resource;
        $options = $import->parse_options;

        return [
            ...(new ImportSummaryResource($import))->toArray($request),
            'received_bytes' => $import->received_bytes,
            'containers' => $import->containers,
            'inner_name' => $import->inner_name,
            'encoding' => $import->encoding === null ? null : [
                'name' => (string) $import->encoding['name'],
                /** @var 'bom'|'utf16_pattern'|'valid_utf8'|'ascii'|'fallback'|'xml_declaration'|'override' */
                'reason' => (string) $import->encoding['reason'],
            ],
            'delimiter' => $import->delimiter,
            'xml_item_path' => $import->xml_item_path,
            'parse_options' => [
                'encoding' => isset($options['encoding']) && is_string($options['encoding']) ? $options['encoding'] : null,
                'delimiter' => isset($options['delimiter']) && is_string($options['delimiter']) ? $options['delimiter'] : null,
                'quoted' => ($options['quoted'] ?? true) !== false,
                'xml_item_path' => isset($options['xml_item_path']) && is_string($options['xml_item_path']) ? $options['xml_item_path'] : null,
            ],
            'sku' => $import->sku === null ? null : [
                'column' => (string) $import->sku['column'],
                /** @var 'high'|'low' */
                'confidence' => (string) $import->sku['confidence'],
                'empty' => (int) $import->sku['empty'],
                'duplicates' => (int) $import->sku['duplicates'],
            ],
            'columns' => $import->columns->map(static fn (ImportColumn $column): array => [
                'position' => $column->position,
                'name' => $column->name,
                'filled' => $column->filled,
                'fill_rate' => (float) $column->fill_rate,
                'max_length' => $column->max_length,
                /** @var ValueKind */
                'kind' => ValueKind::from($column->kind),
                'examples' => $column->examples,
            ])->values()->all(),
            'sample_rows' => array_map(
                static fn (array $row): array => array_combine(array_map(strval(...), array_keys($row)), array_map(strval(...), $row)),
                $import->sample_rows ?? [],
            ),
            'warnings' => array_map(static fn (array $warning): array => [
                'code' => (string) $warning['code'],
                'message' => (string) $warning['message'],
                'count' => (int) $warning['count'],
            ], $import->warnings),
        ];
    }
}
