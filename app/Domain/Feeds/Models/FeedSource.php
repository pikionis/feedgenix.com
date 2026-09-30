<?php

declare(strict_types=1);

namespace App\Domain\Feeds\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a URL feed is fetched from. The URL is encrypted at rest (access tokens often sit in its
 * query) and never returned whole: the API shows the host.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $feed_id
 * @property string $url
 * @property string $host
 * @property string|null $etag
 * @property string|null $last_modified
 */
#[Fillable(['feed_id', 'url', 'host', 'etag', 'last_modified'])]
class FeedSource extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'etag' => null,
        'last_modified' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'feed_id' => 'integer',
            'url' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Feed, $this> */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(Feed::class);
    }
}
