<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Models\SignupInvite;
use App\Models\User;
use Illuminate\Support\Str;

/** Issues a private-beta invite code. The code is returned once and only its hash is stored. */
final class CreateInvite
{
    public function handle(?string $email = null, ?int $days = null, ?string $note = null, ?User $by = null): string
    {
        $code = 'fg-'.Str::lower(Str::random(24));

        $invite = new SignupInvite;
        $invite->forceFill([
            'code_hash' => SignupInvite::hash($code),
            'email' => $email,
            'note' => $note,
            'expires_at' => now()->addDays($days ?? (int) config('feedgenix.invite_days')),
            'created_by' => $by?->id,
        ])->save();

        return $code;
    }
}
