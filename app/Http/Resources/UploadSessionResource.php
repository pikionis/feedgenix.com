<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Ingest\Models\UploadSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A chunked upload: send total_chunks chunks of chunk_size bytes (the last one holds the rest),
 * then complete it.
 *
 * @property UploadSession $resource
 */
final class UploadSessionResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = 'upload';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'filename' => $this->resource->filename,
            'size' => $this->resource->size,
            'chunk_size' => $this->resource->chunk_size,
            'total_chunks' => $this->resource->total_chunks,
            'expires_at' => $this->resource->expires_at->toIso8601String(),
        ];
    }
}
