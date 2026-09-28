<?php

declare(strict_types=1);

use App\Domain\Identity\Actions\ProvisionWorkspace;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;

function provision(User $user): Tenant
{
    return resolve(ProvisionWorkspace::class)->handle($user);
}

it('creates a Free workspace the user owns, is a member of and acts in', function (): void {
    $user = User::factory()->create(['name' => 'Alice Martin']);

    $tenant = provision($user);

    expect($tenant->owner_id)->toBe($user->id)
        ->and($tenant->plan)->toBe('free')
        ->and($tenant->name)->toBe("Alice Martin's workspace")
        ->and($tenant->slug)->toBe('alice-martin')
        ->and($user->fresh()?->current_tenant_id)->toBe($tenant->id)
        ->and($user->roleIn($tenant->id))->toBe('owner');
});

it('is idempotent: a second call returns the same workspace and adds nothing', function (): void {
    $user = User::factory()->create();

    $first = provision($user);
    $second = provision($user->fresh() ?? $user);

    expect($second->id)->toBe($first->id)
        ->and(Tenant::query()->where('owner_id', $user->id)->count())->toBe(1)
        ->and(DB::table('tenant_user')->where('user_id', $user->id)->count())->toBe(1);
});

it('keeps slugs unique', function (): void {
    $slugs = collect(range(1, 3))
        ->map(fn (): string => provision(User::factory()->create(['name' => 'Same Name']))->slug)
        ->all();

    expect($slugs)->toBe(['same-name', 'same-name-2', 'same-name-3']);
});

it('derives the address from the email when the name is empty, transliterates, and falls back', function (string $name, string $email, string $slug): void {
    $user = User::factory()->create(['name' => $name, 'email' => $email]);

    expect(provision($user)->slug)->toBe($slug);
})->with([
    'empty name' => ['', 'shop.owner@example.com', 'shopowner'],
    'greek name' => ['Πέτρος Π', 'p@example.com', 'petros-p'],
    'symbols only' => ['!!!', '---@example.com', 'workspace'],
]);

it('fits the longest allowed name into the workspace name and address', function (): void {
    $tenant = provision(User::factory()->create(['name' => str_repeat('Long name ', 12)])); // 120 chars, the column max

    expect(mb_strlen($tenant->slug))->toBeLessThanOrEqual(40)
        ->and(mb_strlen($tenant->name))->toBeLessThanOrEqual(80);
});

it('survives a concurrent sign-up taking the same address mid-way', function (): void {
    $rival = User::factory()->create();
    $raced = false;
    Tenant::creating(function (Tenant $tenant) use (&$raced, $rival): void {
        if (! $raced) {
            $raced = true;
            // Another request inserts the same slug between our check and our insert.
            DB::table('tenants')->insert([
                'name' => 'Rival', 'slug' => $tenant->slug, 'owner_id' => $rival->id,
                'plan' => 'free', 'usage_anchor_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    });

    $tenant = provision(User::factory()->create(['name' => 'Race Case']));

    expect($tenant->slug)->toBe('race-case-2')->and($tenant->exists)->toBeTrue();
});

it('does not move a user who already acts in another workspace', function (): void {
    $team = Tenant::factory()->create();
    $user = User::factory()->create();
    $team->users()->attach($user->id, ['role' => 'member']);
    $user->forceFill(['current_tenant_id' => $team->id])->save();

    $own = provision($user);

    expect($own->id)->not->toBe($team->id)
        ->and($user->fresh()?->current_tenant_id)->toBe($team->id);
});
