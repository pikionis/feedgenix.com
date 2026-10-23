<?php

declare(strict_types=1);

namespace App\Domain\Export\Models;

use App\Domain\Tenancy\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The secret in a profile's public URL (ADR 0007): looked up by its SHA-256, shown again from its
 * encrypted copy. A rotated token keeps working until `expires_at`, then answers 404.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $profile_id
 * @property string $token_hash
 * @property string $token_encrypted
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $created_at
 * @property-read ExportProfile $profile
 */
#[Hidden(['token_hash', 'token_encrypted'])]
class FeedToken extends Model
{
    use BelongsToTenant;

    /** @var array<string, mixed> */
    protected $attributes = [
        'expires_at' => null,
    ];

    public static function hashOf(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<ExportProfile, $this>
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(ExportProfile::class, 'profile_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'profile_id' => 'integer',
            'token_encrypted' => 'encrypted',
            'expires_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }
}
