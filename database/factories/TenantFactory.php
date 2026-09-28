<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'slug' => 'ws-'.Str::lower(Str::random(10)),
            'owner_id' => User::factory(),
            'plan' => 'free',
            'usage_anchor_at' => now(),
        ];
    }

    public function configure(): static
    {
        // The owner is always a member and acts in the workspace, as after provisioning.
        return $this->afterCreating(function (Tenant $tenant): void {
            $tenant->users()->syncWithoutDetaching([$tenant->owner_id => ['role' => 'owner']]);
            User::query()->whereKey($tenant->owner_id)->whereNull('current_tenant_id')
                ->update(['current_tenant_id' => $tenant->id]);
        });
    }

    public function onPlan(string $plan, string $interval = 'month'): static
    {
        return $this->state(fn (array $attributes): array => [
            'plan' => $plan,
            'billing_interval' => $plan === 'free' ? null : $interval,
            'plan_started_at' => $plan === 'free' ? null : now(),
            'plan_ends_at' => $plan === 'free' ? null : ($interval === 'year' ? now()->addYear() : now()->addMonth()),
        ]);
    }
}
