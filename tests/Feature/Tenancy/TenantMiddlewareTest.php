<?php

declare(strict_types=1);

use App\Domain\Tenancy\Models\TenantLanguage;
use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Route;

beforeEach(function (): void {
    Route::middleware(['web', 'auth', 'tenant'])->group(function (): void {
        Route::get('/_test/context', fn (TenantContext $context): array => [
            'tenant' => $context->tenantId(),
            'system' => $context->isSystem(),
        ]);
        Route::get('/_test/languages/{tenantLanguage}', fn (TenantLanguage $tenantLanguage): array => [
            'code' => $tenantLanguage->language_code,
        ]);
    });
    Route::middleware(['web', 'auth', 'staff'])->get('/_test/admin', fn (TenantContext $context): array => [
        'system' => $context->isSystem(),
    ]);
});

it('acts for the workspace of the signed-in member', function (): void {
    $tenant = Tenant::factory()->create();

    $this->actingAs(User::query()->findOrFail($tenant->owner_id))
        ->getJson('/_test/context')
        ->assertOk()
        ->assertExactJson(['tenant' => $tenant->id, 'system' => false]);
});

it('refuses an account without a workspace', function (): void {
    $this->actingAs(User::factory()->create())->getJson('/_test/context')->assertForbidden();
});

it('refuses a workspace the user is not a member of (a stale or forged current workspace)', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $other = Tenant::factory()->create();
    $user->forceFill(['current_tenant_id' => $other->id])->save();

    $this->actingAs($user)->getJson('/_test/context')->assertForbidden();
});

it('returns 404 for a record of another workspace (context is set before route-model binding)', function (): void {
    [$mine, $theirs] = [Tenant::factory()->create(), Tenant::factory()->create()];
    $own = addLanguage($mine, 'de');
    $foreign = addLanguage($theirs, 'fr');
    $user = User::query()->findOrFail($mine->owner_id);

    $this->actingAs($user)->getJson("/_test/languages/{$own->id}")->assertOk()->assertJson(['code' => 'de']);
    $this->actingAs($user)->getJson("/_test/languages/{$foreign->id}")->assertNotFound();
});

it('gives staff no bypass inside the customer app', function (): void {
    $staff = User::factory()->staff()->withWorkspace()->create();

    $this->actingAs($staff)->getJson('/_test/context')
        ->assertOk()
        ->assertJson(['tenant' => $staff->fresh()?->current_tenant_id, 'system' => false]);
});

it('opens the admin area to staff only, in the system context', function (): void {
    $customer = User::factory()->withWorkspace()->create();
    $staff = staffWithTwoFactor();

    $this->actingAs($customer)->getJson('/_test/admin')->assertForbidden();
    $this->actingAs($staff)->getJson('/_test/admin')->assertForbidden(); // second factor not proven yet
    $this->actingAs($staff)->withSession(twoFactorPassedSession())->getJson('/_test/admin')
        ->assertOk()->assertExactJson(['system' => true]);
});

it('requires authentication before any workspace resolution', function (): void {
    $this->getJson('/_test/context')->assertUnauthorized();
});
