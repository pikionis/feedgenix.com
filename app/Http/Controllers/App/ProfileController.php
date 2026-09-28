<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Http\Requests\App\UpdatePasswordRequest;
use App\Http\Requests\App\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ProfileController
{
    /** Your profile. */
    public function show(Request $request): UserResource
    {
        return new UserResource($this->user($request));
    }

    /** Change your name or interface language. */
    public function update(UpdateProfileRequest $request): UserResource
    {
        $user = $this->user($request);
        $user->fill($request->safe()->only(['name', 'locale']))->save();

        return new UserResource($user);
    }

    /**
     * Change your password (or set a first one after signing up with Google).
     *
     * Every other session of the account is signed out.
     */
    public function updatePassword(UpdatePasswordRequest $request): Response
    {
        $user = $this->user($request);
        $user->forceFill([
            'password' => (string) $request->validated('password'),
            'remember_token' => Str::random(60),
        ])->save();

        $current = $request->hasSession() ? $request->session()->getId() : null;
        DB::table('sessions')->where('user_id', $user->id)
            ->when($current !== null, fn ($query) => $query->where('id', '!=', $current))
            ->delete();

        return response()->noContent();
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }
}
