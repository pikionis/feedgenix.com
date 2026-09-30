<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Models;

use App\Domain\Feeds\Models\Feed;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A chunked upload in progress. Its chunks live under uploads/{tenant}/{id}/{index}: every part of
 * the path comes from the server, never from the client.
 *
 * @property string $id
 * @property int $tenant_id
 * @property int $feed_id
 * @property string $filename shown to the customer only; never part of a path
 * @property int $size
 * @property int $chunk_size
 * @property int $total_chunks
 * @property string $status open or completed
 * @property int|null $import_id
 * @property CarbonImmutable $expires_at
 */
#[Fillable(['feed_id', 'filename', 'size', 'chunk_size', 'total_chunks', 'expires_at'])]
class UploadSession extends Model
{
    use BelongsToTenant;
    use HasUuids;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'open',
        'import_id' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'feed_id' => 'integer',
            'size' => 'integer',
            'chunk_size' => 'integer',
            'total_chunks' => 'integer',
            'import_id' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Feed, $this> */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(Feed::class);
    }

    public function directory(): string
    {
        return "uploads/{$this->tenant_id}/{$this->id}";
    }

    /** The exact length chunk $index must have: the chunk size, the remainder for the last one. */
    public function expectedLength(int $index): int
    {
        return $index === $this->total_chunks - 1
            ? $this->size - $this->chunk_size * ($this->total_chunks - 1)
            : $this->chunk_size;
    }
}
