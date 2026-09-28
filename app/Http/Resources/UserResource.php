<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Billing\Entitlements;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The signed-in person, as the SPA sees them: identity, verification state and the workspace they
 * act in. Never includes the password hash, tokens or anything about other workspaces.
 *
 * @property User $resource
 */
final class UserResource extends JsonResource
{
    /** @var string|null */
    public static $wrap = 'user';

    /**
     * @return array{
     *     id: int,
     *     name: string,
     *     email: string,
     *     initials: string,
     *     avatar_url: string|null,
     *     locale: string,
     *     is_staff: bool,
     *     email_verified: bool,
     *     has_password: bool,
     *     workspace: array{id: int, name: string, slug: string, role: string, plan: string}|null
     * }
     */
    public function toArray(Request $request): array
    {
        $user = $this->resource;

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'initials' => mb_strtoupper(mb_substr(trim($user->name) !== '' ? $user->name : $user->email, 0, 2)),
            'avatar_url' => $user->avatar_url,
            'locale' => $user->locale,
            'is_staff' => $user->is_staff,
            'email_verified' => $user->hasVerifiedEmail(),
            'has_password' => $user->hasPassword(),
            'workspace' => $this->workspace($user),
        ];
    }

    /**
     * @return array{id: int, name: string, slug: string, role: string, plan: string}|null
     */
    private function workspace(User $user): ?array
    {
        if ($user->current_tenant_id === null) {
            return null;
        }

        $role = $user->roleIn($user->current_tenant_id);
        if ($role === null) {
            return null;
        }

        $tenant = Tenant::query()->find($user->current_tenant_id);
        if (! $tenant instanceof Tenant) {
            return null;
        }

        return [
            'id' => $tenant->id,
            'name' => $tenant->name,
            'slug' => $tenant->slug,
            'role' => $role,
            'plan' => Entitlements::for($tenant)->plan(),
        ];
    }
}
