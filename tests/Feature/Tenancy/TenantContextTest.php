<?php

declare(strict_types=1);

use App\Domain\Tenancy\Exceptions\MissingTenantContext;
use App\Domain\Tenancy\TenancyMode;
use App\Domain\Tenancy\TenancyState;

it('starts with no context', function (): void {
    expect(tenancy()->state()->mode)->toBe(TenancyMode::None)
        ->and(tenancy()->tenantId())->toBeNull()
        ->and(tenancy()->isSystem())->toBeFalse();
});

it('requires a tenant id when none is set', function (): void {
    expect(fn (): int => tenancy()->requireTenantId())->toThrow(MissingTenantContext::class);
});

it('switches between tenant, system and none', function (): void {
    tenancy()->set(7);
    expect(tenancy()->tenantId())->toBe(7)->and(tenancy()->isSystem())->toBeFalse();

    tenancy()->system('test');
    expect(tenancy()->tenantId())->toBeNull()->and(tenancy()->isSystem())->toBeTrue();

    tenancy()->clear();
    expect(tenancy()->state()->mode)->toBe(TenancyMode::None);
});

it('restores the previous context after run(), even when the callback throws', function (): void {
    tenancy()->set(1);

    expect(fn (): mixed => tenancy()->run(2, fn () => throw new RuntimeException('boom')))->toThrow(RuntimeException::class);
    expect(tenancy()->tenantId())->toBe(1);

    $seen = tenancy()->runAsSystem('nested', fn (): mixed => tenancy()->run(3, fn (): ?int => tenancy()->tenantId()));
    expect($seen)->toBe(3)->and(tenancy()->tenantId())->toBe(1);
});

it('rejects a system context without a reason and non-positive tenant ids', function (): void {
    expect(fn () => tenancy()->system('  '))->toThrow(InvalidArgumentException::class)
        ->and(fn () => tenancy()->set(0))->toThrow(InvalidArgumentException::class)
        ->and(fn () => tenancy()->set(-4))->toThrow(InvalidArgumentException::class);
});

it('restores an exact snapshot', function (): void {
    tenancy()->system('audit reason');
    $snapshot = tenancy()->state();

    tenancy()->set(9);
    tenancy()->restore($snapshot);

    expect(tenancy()->state())->toEqual(TenancyState::system('audit reason'));
});

it('starts every request with no context', function (): void {
    tenancy()->set(5);

    // A new request resets scoped instances: the context of the previous one never leaks in.
    app()->forgetScopedInstances();

    expect(tenancy()->tenantId())->toBeNull();
});
