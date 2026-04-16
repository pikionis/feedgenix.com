<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    /**
     * Register a new user, create their team, and return an auth token.
     */
    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
            'role' => 'owner',
        ]);

        $teamName = $validated['name'] . "'s Team";
        $slug = Str::slug($teamName) . '-' . Str::random(6);

        $team = Team::create([
            'name' => $teamName,
            'owner_id' => $user->id,
            'slug' => $slug,
            'plan' => 'free',
            'products_used_this_month' => 0,
        ]);

        $user->update(['team_id' => $team->id]);
        $user->refresh();

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user->load('team'),
            'token' => $token,
        ], 201);
    }

    /**
     * Authenticate a user and return an auth token.
     */
    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($validated)) {
            return response()->json([
                'message' => 'The provided credentials are incorrect.',
            ], 401);
        }

        /** @var User $user */
        $user = Auth::user();

        $token = $user->createToken('auth-token')->plainTextToken;

        return response()->json([
            'user' => $user->load('team'),
            'token' => $token,
        ]);
    }

    /**
     * Revoke the current access token (logout).
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    /**
     * Return the authenticated user with their team.
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()->load('team'),
        ]);
    }
}
