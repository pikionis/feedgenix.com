<?php

declare(strict_types=1);

namespace App\Domain\Feeds\Models;

use App\Domain\Ingest\Fetch\Credentials;
use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Where a URL feed is fetched from. The URL and the login are encrypted at rest (access tokens often
 * sit in the URL's query) and never returned: the API shows the host and the login's kind.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $feed_id
 * @property string $url
 * @property string $host
 * @property string|null $auth_type
 * @property array<array-key, mixed>|null $credentials
 * @property string|null $etag
 * @property string|null $last_modified
 */
#[Fillable(['feed_id', 'url', 'host', 'auth_type', 'credentials', 'etag', 'last_modified'])]
#[Hidden(['url', 'credentials'])]
class FeedSource extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'auth_type' => null,
        'credentials' => null,
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
            'credentials' => 'encrypted:array',
        ];
    }

    /** @return BelongsTo<Feed, $this> */
    public function feed(): BelongsTo
    {
        return $this->belongsTo(Feed::class);
    }

    public function login(): ?Credentials
    {
        return $this->credentials === null ? null : Credentials::fromArray($this->credentials);
    }
}
