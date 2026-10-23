<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One text of a product in one language (ADR 0003): a source from the feed, or a target produced
 * for a language. A target remembers the hash and language of the source it was made from, so a
 * changed source makes it stale and a write made from an old source is refused.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $product_id
 * @property string $field
 * @property string $locale
 * @property string $role source or target
 * @property string|null $value
 * @property string $status ready, pending, stale, qa_failed or held
 * @property string $origin source, composed, ai, tm, manual or approved
 * @property string|null $source_hash
 * @property string|null $source_locale
 * @property string|null $prompt
 * @property string|null $model
 * @property array<string, mixed>|null $checks
 * @property CarbonImmutable|null $updated_at
 * @property-read Product $product
 */
class ProductText extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'value' => null,
        'source_hash' => null,
        'source_locale' => null,
        'prompt' => null,
        'model' => null,
        'checks' => null,
    ];

    /** The hash a text is compared by: its exact characters. */
    public static function hashOf(string $value): string
    {
        return hash('sha256', $value);
    }

    /**
     * @return BelongsTo<Product, $this>
     */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'product_id' => 'integer',
            'checks' => 'array',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
