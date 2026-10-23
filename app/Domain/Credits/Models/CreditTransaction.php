<?php

declare(strict_types=1);

namespace App\Domain\Credits\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One movement of a wallet's balance, with the balance after it. Append-only: a database trigger
 * rejects every UPDATE.
 *
 * @property int $id
 * @property int $tenant_id
 * @property string $kind grant, purchase, ai_usage, refund or adjustment
 * @property int $amount signed millicredits
 * @property int $balance_after
 * @property int|null $reservation_id
 * @property string|null $external_ref
 * @property string|null $note
 * @property CarbonImmutable $created_at
 */
class CreditTransaction extends Model
{
    use BelongsToTenant;

    public const null UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'amount' => 'integer',
            'balance_after' => 'integer',
            'reservation_id' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
