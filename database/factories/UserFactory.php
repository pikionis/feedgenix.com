<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Identity\Actions\ProvisionWorkspace;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password = null;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => ['email_verified_at' => null]);
    }

    /** A platform operator. */
    public function staff(): static
    {
        return $this->state(fn (array $attributes): array => ['is_staff' => true]);
    }

    /** A user who signs in with Google only (no password). */
    public function googleOnly(): static
    {
        return $this->state(fn (array $attributes): array => [
            'password' => null,
            'google_id' => (string) fake()->unique()->numberBetween(10_000_000, 99_999_999),
        ]);
    }

    /** The user with their own workspace, as after sign-up. */
    public function withWorkspace(): static
    {
        return $this->afterCreating(function (User $user): void {
            resolve(ProvisionWorkspace::class)->handle($user);
        });
    }
}
