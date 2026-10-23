<?php

declare(strict_types=1);

namespace App\Domain\Credits\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;

/**
 * Escrow for one piece of paid work (ADR 0005). AI calls add to consumed, never past amount; settling
 * charges consumed to the wallet and releases the rest.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $reference
 * @property int $amount
 * @property int $consumed
 * @property string $status open or settled
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $settled_at
 */
class CreditReservation extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'consumed' => 0,
        'status' => 'open',
        'settled_at' => null,
    ];

    public function remaining(): int
    {
        return $this->amount - $this->consumed;
    }

    public function isOpenAt(CarbonInterface $now): bool
    {
        return $this->status === 'open' && $this->expires_at->greaterThan($now);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'amount' => 'integer',
            'consumed' => 'integer',
            'expires_at' => 'immutable_datetime',
            'settled_at' => 'immutable_datetime',
        ];
    }
}
