<?php

declare(strict_types=1);

namespace App\Domain\Identity\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A private-beta invite code (only its hash is stored).
 *
 * @property int $id
 * @property string $code_hash
 * @property string|null $email
 * @property string|null $note
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $used_at
 * @property int|null $used_by
 * @property int|null $created_by
 */
class SignupInvite extends Model
{
    /** @var array<string, mixed> */
    protected $attributes = [
        'email' => null,
        'note' => null,
        'used_at' => null,
        'used_by' => null,
        'created_by' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'immutable_datetime',
            'used_at' => 'immutable_datetime',
            'used_by' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public static function hash(string $code): string
    {
        return hash('sha256', mb_strtolower(trim($code)));
    }
}
