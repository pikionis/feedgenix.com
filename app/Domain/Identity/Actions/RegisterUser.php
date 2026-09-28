<?php

declare(strict_types=1);

namespace App\Domain\Identity\Actions;

use App\Domain\Identity\Exceptions\RegistrationClosed;
use App\Domain\Identity\RegistrationMode;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Email sign-up: the account, its own workspace and (in the private beta) the redeemed invite, in
 * one transaction. The verification email is sent only after it commits.
 */
final readonly class RegisterUser
{
    public function __construct(
        private ProvisionWorkspace $provisionWorkspace,
        private RedeemInvite $redeemInvite,
    ) {}

    /**
     * @param  array{name: string, email: string, password: string}  $data
     */
    public function handle(array $data, ?string $inviteCode): User
    {
        $mode = RegistrationMode::current();
        if ($mode === RegistrationMode::Closed) {
            throw new RegistrationClosed;
        }

        try {
            $user = DB::transaction(function () use ($data, $inviteCode, $mode): User {
                $invite = $mode === RegistrationMode::Invite
                    ? $this->redeemInvite->handle($inviteCode, $data['email'])
                    : null;

                $user = User::query()->create([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                ]);

                $this->provisionWorkspace->handle($user);
                $invite?->forceFill(['used_by' => $user->id])->save();

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            // Two sign-ups for the same address raced past validation: answer like validation would.
            throw ValidationException::withMessages(['email' => [trans('validation.unique', ['attribute' => 'email'])]]);
        }

        event(new Registered($user));

        return $user;
    }
}
