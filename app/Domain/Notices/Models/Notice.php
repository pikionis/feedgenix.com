<?php

declare(strict_types=1);

namespace App\Domain\Notices\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Something a workspace is told (ADR 0025), with what it can do about it. Never money beyond
 * credits and the plan's own prices: no cost, no markup, no provider price.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $kind
 * @property string $key
 * @property string $level info, warn or action
 * @property string $title
 * @property string $body
 * @property string|null $action_label
 * @property string|null $action_path an app path ("/app/settings")
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $emailed_at
 * @property CarbonImmutable|null $created_at
 */
class Notice extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'level' => 'info',
        'action_label' => null,
        'action_path' => null,
        'read_at' => null,
        'emailed_at' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'read_at' => 'immutable_datetime',
            'emailed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
