<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Ingest\Format;
use App\Domain\Ingest\Models\Import;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An import at a glance: how it went, never the stored file's location.
 *
 * @property Import $resource
 */
final class ImportSummaryResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = 'import';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $import = $this->resource;

        return [
            'id' => $import->id,
            'feed_id' => $import->feed_id,
            /** @var 'upload'|'fetch'|'reread' */
            'origin' => $import->origin,
            'status' => $import->status,
            'original_name' => $import->original_name,
            /** @var Format|null */
            'format' => $import->format === null ? null : Format::from($import->format),
            'row_count' => $import->row_count,
            'column_count' => $import->column_count,
            'reuses_import_id' => $import->reuses_import_id,
            'failure' => $import->failure_reason === null ? null : [
                'reason' => $import->failure_reason,
                'message' => (string) $import->failure_message,
                'retry_after_seconds' => $import->retry_after_seconds,
            ],
            'started_at' => $import->started_at?->toIso8601String(),
            'finished_at' => $import->finished_at?->toIso8601String(),
            'created_at' => $import->created_at?->toIso8601String(),
        ];
    }
}
