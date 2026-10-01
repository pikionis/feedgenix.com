<?php

declare(strict_types=1);

namespace App\Domain\Ingest\Models;

use App\Domain\Feeds\Models\Feed;
use App\Domain\Ingest\ImportStatus;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One reading of a feed.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $feed_id
 * @property string $origin upload, fetch or reread
 * @property ImportStatus $status
 * @property int|null $reuses_import_id the profiled import an unchanged or re-read import points to
 * @property string|null $original_name
 * @property string|null $staging_path where an upload waits for the job (local disk)
 * @property int|null $received_bytes
 * @property string|null $content_sha256
 * @property string|null $blob_path the received bytes, stored once per workspace and content
 * @property array<string, mixed> $parse_options
 * @property string|null $format
 * @property list<string> $containers
 * @property string|null $inner_name
 * @property array{name: string, reason: string}|null $encoding
 * @property string|null $delimiter
 * @property string|null $xml_item_path
 * @property int|null $row_count
 * @property int|null $column_count
 * @property string|null $column_signature
 * @property array{column: string, confidence: string, empty: int, duplicates: int}|null $sku
 * @property list<array<array-key, string>>|null $sample_rows
 * @property list<array{code: string, message: string, count: int}> $warnings
 * @property string|null $failure_reason
 * @property string|null $failure_message
 * @property int|null $retry_after_seconds
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property-read Feed $feed
 */
#[Fillable(['feed_id', 'origin', 'status', 'reuses_import_id', 'original_name', 'staging_path', 'received_bytes', 'content_sha256', 'blob_path', 'parse_options'])]
class Import extends Model
{
    use BelongsToTenant;

    /** What a summary shows (ImportSummaryResource): never the samples, the columns or where the file is. */
    public const array SUMMARY_COLUMNS = [
        'id', 'feed_id', 'origin', 'status', 'original_name', 'format', 'row_count', 'column_count', 'reuses_import_id',
        'failure_reason', 'failure_message', 'retry_after_seconds', 'started_at', 'finished_at', 'created_at',
    ];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'queued',
        'reuses_import_id' => null,
        'original_name' => null,
        'staging_path' => null,
        'received_bytes' => null,
        'content_sha256' => null,
        'blob_path' => null,
        'parse_options' => '{}',
        'format' => null,
        'containers' => '[]',
        'inner_name' => null,
        'encoding' => null,
        'delimiter' => null,
        'xml_item_path' => null,
        'row_count' => null,
        'column_count' => null,
        'column_signature' => null,
        'sku' => null,
        'sample_rows' => null,
        'warnings' => '[]',
        'failure_reason' => null,
        'failure_message' => null,
        'retry_after_seconds' => null,
        'started_at' => null,
        'finished_at' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'feed_id' => 'integer',
            'status' => ImportStatus::class,
            'reuses_import_id' => 'integer',
            'received_bytes' => 'integer',
            'parse_options' => 'array',
            'containers' => 'array',
            'encoding' => 'array',
            'row_count' => 'integer',
            'column_count' => 'integer',
            'sku' => 'array',
            'sample_rows' => 'array',
            'warnings' => 'array',
            'retry_after_seconds' => 'integer',
            'started_at' => 'immutable_datetime',
            'finished_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Feed, $this> */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(Feed::class);
    }

    /** @return HasMany<ImportColumn, $this> */
    public function columns(): HasMany
    {
        return $this->hasMany(ImportColumn::class)->orderBy('position');
    }
}
