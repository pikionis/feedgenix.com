<?php

declare(strict_types=1);

namespace App\Domain\Feeds\Models;

use App\Domain\Ingest\ImportStatus;
use App\Domain\Ingest\Models\Import;
use App\Domain\Ingest\Models\UploadSession;
use App\Domain\Ingest\ParseOptions;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A customer's feed: where its products come from and how to read them.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $source url or upload
 * @property string $status active or paused
 * @property array<string, mixed> $parse_options
 * @property CarbonImmutable|null $created_at
 * @property-read FeedSource|null $feedSource
 * @property-read Import|null $latestImport
 * @property-read Import|null $profileImport
 */
#[Fillable(['name', 'source', 'parse_options'])]
class Feed extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
        'parse_options' => '{}',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'parse_options' => 'array',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function parseOptions(): ParseOptions
    {
        return ParseOptions::fromArray($this->parse_options);
    }

    /** @return HasOne<FeedSource, $this> */
    public function feedSource(): HasOne
    {
        return $this->hasOne(FeedSource::class);
    }

    /** @return HasMany<Import, $this> */
    public function imports(): HasMany
    {
        return $this->hasMany(Import::class);
    }

    /** @return HasMany<UploadSession, $this> */
    public function uploads(): HasMany
    {
        return $this->hasMany(UploadSession::class);
    }

    /**
     * The most recent import, whatever its outcome.
     *
     * @return HasOne<Import, $this>
     */
    public function latestImport(): HasOne
    {
        return $this->hasOne(Import::class)->latestOfMany();
    }

    /**
     * The import whose profile is current: the last one that was read successfully.
     *
     * @return HasOne<Import, $this>
     */
    public function profileImport(): HasOne
    {
        return $this->hasOne(Import::class)->ofMany(['id' => 'max'], static function ($query): void {
            $query->where('status', ImportStatus::Profiled->value);
        });
    }
}
