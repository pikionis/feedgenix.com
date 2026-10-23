<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One product of a feed, by SKU. Golden and pass-through values are kept exactly as the source
 * wrote them (rule 1); texts live in product_texts. Written by the catalogue sync only.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $feed_id
 * @property string $sku
 * @property array<string, string> $source_values golden and pass-through field => exact source text
 * @property string $values_hash
 * @property string $status active or disappeared
 * @property int|null $first_seen_import_id
 * @property int|null $last_seen_import_id
 * @property CarbonImmutable|null $disappeared_at
 * @property CarbonImmutable|null $updated_at
 */
class Product extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'source_values' => '{}',
        'status' => 'active',
        'first_seen_import_id' => null,
        'last_seen_import_id' => null,
        'disappeared_at' => null,
    ];

    /**
     * @return HasMany<ProductText, $this>
     */
    public function texts(): HasMany
    {
        return $this->hasMany(ProductText::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'feed_id' => 'integer',
            'source_values' => 'array',
            'first_seen_import_id' => 'integer',
            'last_seen_import_id' => 'integer',
            'disappeared_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
