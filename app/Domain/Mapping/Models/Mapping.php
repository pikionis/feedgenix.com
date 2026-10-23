<?php

declare(strict_types=1);

namespace App\Domain\Mapping\Models;

use App\Domain\Feeds\Models\Feed;
use App\Domain\Ingest\Models\Import;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * How one feed's columns (one column signature) become canonical fields (ADR 0018).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $feed_id
 * @property string $column_signature
 * @property int|null $import_id
 * @property string $status draft or approved
 * @property string|null $source_locale
 * @property list<array{column: string, reason: string}> $skipped_columns
 * @property string $ai_status none, queued, running, done or failed
 * @property string|null $ai_failure
 * @property CarbonImmutable|null $ai_requested_at
 * @property int $version
 * @property int|null $approved_by
 * @property CarbonImmutable|null $approved_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Feed $feed
 * @property-read Import|null $import
 */
class Mapping extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'source_locale' => null,
        'skipped_columns' => '[]',
        'ai_status' => 'none',
        'ai_failure' => null,
        'ai_requested_at' => null,
        'version' => 1,
        'approved_by' => null,
        'approved_at' => null,
    ];

    /**
     * @return BelongsTo<Feed, $this>
     */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(Feed::class);
    }

    /**
     * @return BelongsTo<Import, $this>
     */
    public function import(): BelongsTo
    {
        return $this->belongsTo(Import::class);
    }

    /**
     * @return HasMany<MappingField, $this>
     */
    public function fields(): HasMany
    {
        return $this->hasMany(MappingField::class);
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'feed_id' => 'integer',
            'import_id' => 'integer',
            'skipped_columns' => 'array',
            'ai_requested_at' => 'immutable_datetime',
            'version' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
