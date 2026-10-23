<?php

declare(strict_types=1);

namespace App\Domain\Translation\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * One piece of AI work (ADR 0006): texts of one field from one language into another. The database
 * is the authority; the queue only carries it. A lease fences off a worker that outlived it, and
 * every write is fenced by the source hash each item was planned from.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $run_id
 * @property int $feed_id
 * @property string $field
 * @property string $source_locale
 * @property string $target_locale
 * @property array<int|string, string> $items product id => source hash (JSON turns the ids into int keys)
 * @property int $depth splits so far (a misaligned answer splits the unit in two)
 * @property string $status pending, leased, done, failed, held or split
 * @property string|null $lease_token
 * @property CarbonImmutable|null $lease_until
 * @property int $attempts
 * @property int|null $reservation_id
 * @property string|null $hold_reason
 * @property string|null $failure_reason
 * @property CarbonImmutable|null $available_at
 */
#[Table(name: 'ai_work_units')]
class WorkUnit extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'depth' => 0,
        'status' => 'pending',
        'lease_token' => null,
        'lease_until' => null,
        'attempts' => 0,
        'reservation_id' => null,
        'hold_reason' => null,
        'failure_reason' => null,
        'available_at' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'run_id' => 'integer',
            'feed_id' => 'integer',
            'items' => 'array',
            'depth' => 'integer',
            'attempts' => 'integer',
            'reservation_id' => 'integer',
            'lease_until' => 'immutable_datetime',
            'available_at' => 'immutable_datetime',
        ];
    }
}
