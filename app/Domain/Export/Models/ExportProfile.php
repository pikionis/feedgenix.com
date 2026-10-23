<?php

declare(strict_types=1);

namespace App\Domain\Export\Models;

use App\Domain\Feeds\Models\Feed;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a channel pulls from a feed (ADR 0007): a preset's columns, written in a dialect, in one
 * file with a column per language (wide) or one file per language, for the chosen languages. A
 * paused profile keeps serving its last build and builds no new one.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $feed_id
 * @property string $name
 * @property string $preset
 * @property string $dialect csv or tsv
 * @property string $layout wide or per_language
 * @property list<string> $locales
 * @property string $status active or paused
 * @property int $version
 * @property int|null $published_artifact_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Feed $feed
 * @property-read ExportArtifact|null $published
 */
class ExportProfile extends Model
{
    use BelongsToTenant;

    public const array DIALECTS = ['csv', 'tsv'];

    public const array LAYOUTS = ['wide', 'per_language'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'locales' => '[]',
        'status' => 'active',
        'version' => 1,
        'published_artifact_id' => null,
    ];

    /**
     * @return BelongsTo<Feed, $this>
     */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(Feed::class);
    }

    /**
     * @return BelongsTo<ExportArtifact, $this>
     */
    public function published(): BelongsTo
    {
        return $this->belongsTo(ExportArtifact::class, 'published_artifact_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'feed_id' => 'integer',
            'locales' => 'array',
            'version' => 'integer',
            'published_artifact_id' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
