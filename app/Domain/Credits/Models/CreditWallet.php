<?php

declare(strict_types=1);

namespace App\Domain\Credits\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Model;

/**
 * A workspace's prepaid credits, in millicredits. reserved is the part of balance held by open
 * reservations; only the ledger actions change either, with conditional UPDATEs (ADR 0016).
 *
 * @property int $tenant_id
 * @property int $balance
 * @property int $reserved
 * @property CarbonImmutable|null $updated_at
 */
#[WithoutIncrementing]
class CreditWallet extends Model
{
    use BelongsToTenant;

    protected $primaryKey = 'tenant_id';

    /** @var array<string, mixed> */
    protected $attributes = [
        'balance' => 0,
        'reserved' => 0,
    ];

    public function available(): int
    {
        return $this->balance - $this->reserved;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'balance' => 'integer',
            'reserved' => 'integer',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
