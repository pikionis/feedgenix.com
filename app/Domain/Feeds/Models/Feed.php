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
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A customer's feed: where its products come from and how to read them.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $name
 * @property string $source url or upload
 * @property string $status active or paused
 * @property array<string, mixed> $parse_options
 * @property CarbonImmutable|null $content_changed_at when its exports last had something new to show
 * @property CarbonImmutable|null $plan_paused_at over the plan's feed limit: no new files or AI work, its exports keep serving
 * @property CarbonImmutable|null $created_at
 * @property-read FeedSource|null $feedSource
 * @property-read Import|null $latestImport
 * @property-read int|null $profile_import_id only with the withSummary() scope
 */
#[Fillable(['name', 'source', 'parse_options'])]
class Feed extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'active',
        'parse_options' => '{}',
        'content_changed_at' => null,
        'plan_paused_at' => null,
    ];

    /** The catalogue changed in a way its exports show: the reconciler builds them again (ADR 0022). */
    public static function contentChanged(int $feedId): void
    {
        self::query()->whereKey($feedId)->update(['content_changed_at' => now()]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'parse_options' => 'array',
            'content_changed_at' => 'immutable_datetime',
            'plan_paused_at' => 'immutable_datetime',
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
     * What the API shows of a feed: its source, the latest import's summary (never its samples: the
     * feed list is polled while imports run) and `profile_import_id`, the import whose profile is
     * current (the last one read successfully).
     *
     * @param  Builder<static>  $query
     */
    #[Scope]
    protected function withSummary(Builder $query): void
    {
        $columns = array_map(static fn (string $column): string => 'imports.'.$column, Import::SUMMARY_COLUMNS);

        $query->with(['feedSource', 'latestImport' => static fn (Relation $latest): Relation => $latest->select($columns)])
            ->withMax(['imports as profile_import_id' => static fn (Builder $imports): Builder => $imports->where('status', ImportStatus::Profiled->value)], 'id');
    }
}
