<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;

/**
 * A person who signs in. Workspaces (tenants) own the data; a user acts in one at a time.
 *
 * `is_staff` (platform operator) and `current_tenant_id` are never mass assignable: set them with
 * forceFill() in code that has checked the right to do so.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property bool $is_staff
 * @property int|null $current_tenant_id
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes SHA-256 hashes of the unused codes
 * @property CarbonImmutable|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_step
 */
#[Fillable(['name', 'email', 'password', 'locale', 'avatar_url'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The database defaults, so a freshly created model reads them without a reload.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'locale' => 'en',
        'is_staff' => false,
        'current_tenant_id' => null,
        'google_id' => null,
        'avatar_url' => null,
        'two_factor_secret' => null,
        'two_factor_recovery_codes' => null,
        'two_factor_confirmed_at' => null,
        'two_factor_last_step' => null,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_staff' => 'boolean',
            'current_tenant_id' => 'integer',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'array',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'two_factor_last_step' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)->withPivot('role')->withTimestamps();
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function currentTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'current_tenant_id');
    }

    /** False for an account that has only ever signed in with Google. */
    public function hasPassword(): bool
    {
        return $this->getAttribute('password') !== null;
    }

    /** The user's role in a workspace, or null when they are not a member. */
    public function roleIn(int $tenantId): ?string
    {
        $role = DB::table('tenant_user')
            ->where('tenant_id', $tenantId)
            ->where('user_id', $this->id)
            ->value('role');

        return is_string($role) ? $role : null;
    }
}
