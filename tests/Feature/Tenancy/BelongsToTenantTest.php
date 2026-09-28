<?php

declare(strict_types=1);

use App\Domain\Tenancy\Exceptions\CrossTenantWrite;
use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Domain\Tenancy\Models\TenantLanguage;
use App\Models\Tenant;

it('refuses to query tenant-owned data without a context instead of returning everything', function (): void {
    addLanguage(Tenant::factory()->create(), 'de');

    expect(fn () => TenantLanguage::query()->get())->toThrow(MissingTenantContext::class)
        ->and(fn () => TenantLanguage::query()->count())->toThrow(MissingTenantContext::class);
});

it('scopes every query to the current workspace', function (): void {
    [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
    addLanguage($a, 'de');
    addLanguage($a, 'el');
    $foreign = addLanguage($b, 'fr');

    tenancy()->set($a->id);

    expect(TenantLanguage::query()->orderBy('language_code')->pluck('language_code')->all())->toBe(['de', 'el'])
        ->and(TenantLanguage::query()->find($foreign->id))->toBeNull()
        ->and(TenantLanguage::query()->whereKey($foreign->id)->exists())->toBeFalse();
});

it('stamps new rows with the current workspace', function (): void {
    $a = Tenant::factory()->create();

    $row = tenancy()->run($a->id, fn () => TenantLanguage::query()->create(['language_code' => 'it']));

    expect($row->tenant_id)->toBe($a->id);
});

it('refuses to create a row for another workspace', function (): void {
    [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
    tenancy()->set($a->id);

    $row = new TenantLanguage(['language_code' => 'it']);
    $row->forceFill(['tenant_id' => $b->id]);

    expect(fn () => $row->save())->toThrow(CrossTenantWrite::class);
});

it('refuses to move a row to another workspace', function (): void {
    [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
    $row = addLanguage($a, 'it');
    tenancy()->set($a->id);

    $row->forceFill(['tenant_id' => $b->id]);

    expect(fn () => $row->save())->toThrow(CrossTenantWrite::class);
});

it('needs an explicit workspace to create rows in the system context', function (): void {
    $a = Tenant::factory()->create();
    tenancy()->system('backfill');

    expect(fn () => TenantLanguage::query()->create(['language_code' => 'it']))->toThrow(MissingTenantContext::class);

    $row = new TenantLanguage(['language_code' => 'it']);
    $row->forceFill(['tenant_id' => $a->id])->save();

    expect($row->exists)->toBeTrue();
});

it('lets the system context read every workspace', function (): void {
    addLanguage(Tenant::factory()->create(), 'de');
    addLanguage(Tenant::factory()->create(), 'fr');

    $all = tenancy()->runAsSystem('report', fn () => TenantLanguage::query()->count());

    expect($all)->toBe(2);
});
