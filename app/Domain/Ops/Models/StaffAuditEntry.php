<?php

declare(strict_types=1);

namespace App\Domain\Ops\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * One line of the staff trail (ADR 0026): who, what, which workspace. Append-only (a trigger
 * rejects every UPDATE and DELETE), so it is written once with StaffAudit and never saved again.
 *
 * @property int $id
 * @property int $user_id
 * @property string $user_email
 * @property string $action
 * @property string $method
 * @property string $path
 * @property int $status the HTTP status the request got
 * @property int|null $subject_tenant_id
 * @property array<string, mixed>|null $details
 * @property string|null $ip
 * @property CarbonImmutable|null $created_at
 */
#[Table(name: 'staff_audit')]
class StaffAuditEntry extends Model
{
    public const null UPDATED_AT = null;

    /** @var array<string, mixed> */
    protected $attributes = [
        'subject_tenant_id' => null,
        'details' => null,
        'ip' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'status' => 'integer',
            'subject_tenant_id' => 'integer',
            'details' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }
}
