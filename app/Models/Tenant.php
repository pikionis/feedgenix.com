<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A customer workspace. It owns every piece of customer data (feeds, products, translations,
 * credits) through tenant_id.
 *
 * Only the name is mass assignable. Owner, plan, period, limits and platform flags are written by
 * provisioning, staff commands and billing webhooks through forceFill().
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $owner_id
 * @property string $plan
 * @property string|null $billing_interval
 * @property string $plan_status
 * @property CarbonImmutable|null $plan_started_at
 * @property CarbonImmutable|null $plan_ends_at
 * @property CarbonImmutable $usage_anchor_at
 * @property array<string, int|null>|null $limits_override
 * @property bool $overage_enabled
 * @property bool $is_internal
 * @property CarbonImmutable|null $frozen_at
 * @property CarbonImmutable|null $created_at
 */
#[Fillable(['name'])]
class Tenant extends Model
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    /**
     * The database defaults, so a freshly created model reads them without a reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'plan' => 'free',
        'billing_interval' => null,
        'plan_status' => 'active',
        'plan_started_at' => null,
        'plan_ends_at' => null,
        'limits_override' => null,
        'overage_enabled' => true,
        'ai_paused_at' => null,
        'ai_paused_reason' => null,
        'is_internal' => false,
        'frozen_at' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'owner_id' => 'integer',
            'plan_started_at' => 'immutable_datetime',
            'plan_ends_at' => 'immutable_datetime',
            'usage_anchor_at' => 'immutable_datetime',
            'limits_override' => 'array',
            'overage_enabled' => 'boolean',
            'ai_paused_at' => 'immutable_datetime',
            'is_internal' => 'boolean',
            'frozen_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * @return BelongsToMany<User, $this>
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class)->withPivot('role')->withTimestamps();
    }

    /** A frozen workspace keeps its data but never processes or calls AI again. */
    public function isFrozen(): bool
    {
        return $this->frozen_at !== null;
    }
}
