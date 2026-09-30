<?php

declare(strict_types=1);

use App\Domain\Tenancy\Models\TenantLanguage;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;

/** @return list<string> */
function targetCodes(Tenant $tenant): array
{
    return tenancy()->run($tenant->id, fn (): array => TenantLanguage::query()->orderBy('language_code')->pluck('language_code')->all());
}

it('shows the plan in force, the limits and the usage, never money', function (): void {
    $tenant = Tenant::factory()->create();
    addLanguage($tenant, 'de');

    $response = $this->actingAs(owner($tenant))->getJson('/api/v1/workspace')->assertOk()
        ->assertJsonPath('workspace.role', 'owner')
        ->assertJsonPath('plan.key', 'free')
        ->assertJsonPath('entitlements.limits.languages', 1)
        ->assertJsonPath('entitlements.used.languages', 1)
        ->assertJsonPath('entitlements.limits.products', 500)
        ->assertJsonPath('languages.0.code', 'de');

    expect((string) $response->getContent())->not->toContain('beta_ai_budget')->not->toContain('markup')->not->toContain('internal');
});

it('shows a lapsed paid plan as Free', function (): void {
    $tenant = Tenant::factory()->onPlan('pro')->create(['plan_ends_at' => now()->subDays(10)]);

    $this->actingAs(owner($tenant))->getJson('/api/v1/workspace')
        ->assertOk()->assertJsonPath('plan.key', 'free')->assertJsonPath('entitlements.paid_until', null);
});

it('lets the owner and admins rename the workspace, not members', function (): void {
    $tenant = Tenant::factory()->create();

    $this->actingAs(owner($tenant))->putJson('/api/v1/workspace', ['name' => 'Alpine Shop'])
        ->assertOk()->assertJsonPath('workspace.name', 'Alpine Shop');
    $this->actingAs(memberOf($tenant, 'admin'))->putJson('/api/v1/workspace', ['name' => 'Admin Shop'])->assertOk();
    $this->actingAs(memberOf($tenant, 'member'))->putJson('/api/v1/workspace', ['name' => 'Member Shop'])->assertForbidden();
    $this->actingAs(owner($tenant))->putJson('/api/v1/workspace', ['name' => 'x'])->assertUnprocessable();

    expect($tenant->fresh()?->name)->toBe('Admin Shop');
});

it('sets the target languages, matching codes case-insensitively and collapsing duplicates', function (): void {
    $tenant = Tenant::factory()->onPlan('pro')->create();

    $this->actingAs(owner($tenant))->putJson('/api/v1/workspace/languages', ['languages' => ['DE', 'de', 'pt-br', 'EL']])
        ->assertOk()
        ->assertJsonPath('entitlements.used.languages', 3);

    expect(targetCodes($tenant))->toBe(['de', 'el', 'pt-BR']);

    $this->actingAs(owner($tenant))->putJson('/api/v1/workspace/languages', ['languages' => ['fr']])->assertOk();
    expect(targetCodes($tenant))->toBe(['fr']);

    $this->actingAs(owner($tenant))->putJson('/api/v1/workspace/languages', ['languages' => []])->assertOk();
    expect(targetCodes($tenant))->toBe([]);
});

it('answers 402 with the plan that fits, and changes nothing, when the list is over the limit', function (): void {
    $tenant = Tenant::factory()->create();
    addLanguage($tenant, 'de');

    $this->actingAs(owner($tenant))->putJson('/api/v1/workspace/languages', ['languages' => ['fr', 'it']])
        ->assertStatus(402)
        ->assertExactJson([
            'error' => 'plan_limit',
            'limit' => 'languages',
            'allowed' => 1,
            'used' => 0,
            'requested' => 2,
            'plan' => 'free',
            'upgrade_to' => 'pro',
            'message' => 'Your Free plan includes 1 target language. Pro gives you 5 target languages.',
        ]);

    expect(targetCodes($tenant))->toBe(['de']);
});

it('rejects languages outside the registry', function (): void {
    $tenant = Tenant::factory()->onPlan('pro')->create();

    $this->actingAs(owner($tenant))->putJson('/api/v1/workspace/languages', ['languages' => ['de', 'klingon']])
        ->assertUnprocessable()->assertJsonValidationErrors('languages.1');

    expect(targetCodes($tenant))->toBe([]);
});

it('lets only the owner and admins change the languages', function (): void {
    $tenant = Tenant::factory()->onPlan('pro')->create();

    $this->actingAs(memberOf($tenant, 'member'))->putJson('/api/v1/workspace/languages', ['languages' => ['de']])->assertForbidden();

    expect(targetCodes($tenant))->toBe([]);
});

it('never touches another workspace', function (): void {
    [$mine, $theirs] = [Tenant::factory()->onPlan('pro')->create(), Tenant::factory()->create()];
    addLanguage($theirs, 'it');

    $this->actingAs(owner($mine))->putJson('/api/v1/workspace/languages', ['languages' => ['de']])->assertOk();

    expect(targetCodes($theirs))->toBe(['it'])->and(targetCodes($mine))->toBe(['de']);
});

it('serialises concurrent language changes on the workspace row', function (): void {
    $tenant = Tenant::factory()->onPlan('pro')->create();
    $locks = [];
    DB::listen(function ($query) use (&$locks): void {
        if (str_contains($query->sql, 'from "tenants"') && str_contains($query->sql, 'for update')) {
            $locks[] = $query->sql;
        }
    });

    $this->actingAs(owner($tenant))->putJson('/api/v1/workspace/languages', ['languages' => ['de']])->assertOk();

    expect($locks)->toHaveCount(1);
});
