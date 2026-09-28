<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gives a user their own workspace: a Free tenant they own, their owner membership, and the
 * workspace they act in. Used by every sign-up path (email and Google). Idempotent: a user who
 * already owns a workspace gets that one back.
 */
final class ProvisionWorkspace
{
    public function handle(User $user): Tenant
    {
        return DB::transaction(function () use ($user): Tenant {
            $tenant = Tenant::query()->where('owner_id', $user->id)->orderBy('id')->first()
                ?? $this->create($user);

            $tenant->users()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);

            if ($user->current_tenant_id === null) {
                $user->forceFill(['current_tenant_id' => $tenant->id])->save();
            }

            return $tenant;
        });
    }

    private function create(User $user): Tenant
    {
        $base = $this->slugBase($user);

        // Concurrent sign-ups can race for the same slug: each attempt runs in a savepoint, so a
        // unique violation only costs that attempt.
        for ($attempt = 1; $attempt <= 20; $attempt++) {
            $slug = match (true) {
                $attempt === 1 => $base,
                $attempt <= 10 => $base.'-'.$attempt,
                default => $base.'-'.Str::lower(Str::random(6)),
            };

            if (Tenant::query()->where('slug', $slug)->exists()) {
                continue;
            }

            try {
                return DB::transaction(function () use ($user, $slug): Tenant {
                    $tenant = new Tenant(['name' => $this->workspaceName($user)]);
                    $tenant->forceFill([
                        'slug' => $slug,
                        'owner_id' => $user->id,
                        'plan' => 'free',
                        'usage_anchor_at' => now(),
                    ])->save();

                    return $tenant;
                });
            } catch (UniqueConstraintViolationException) {
                continue;
            }
        }

        throw new \RuntimeException("Could not find a free workspace address for [{$base}].");
    }

    private function slugBase(User $user): string
    {
        $source = trim($user->name) !== '' ? $user->name : Str::before($user->email, '@');
        $slug = Str::limit(Str::slug($source), 40, '');

        return trim($slug, '-') !== '' ? trim($slug, '-') : 'workspace';
    }

    private function workspaceName(User $user): string
    {
        $name = trim($user->name) !== '' ? trim($user->name) : Str::before($user->email, '@');

        return Str::limit($name, 60, '')."'s workspace";
    }
}
