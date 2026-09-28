<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Database\TenantSessionBinder;
use App\Domain\Tenancy\Exceptions\MissingTenantContext;

/**
 * Which workspace the current request or job acts for (ADR 0002).
 *
 * It fails closed: with no context, tenant-owned models refuse to load and PostgreSQL row-level
 * security returns no rows. The system context is explicit and carries a reason. Every change is
 * mirrored into the database session (app.tenant_id / app.bypass) that the RLS policies read.
 *
 * Bound as a scoped singleton, so a new request or queued job starts with no context.
 */
final class TenantContext
{
    private TenancyState $state;

    public function __construct(private readonly TenantSessionBinder $binder)
    {
        $this->state = TenancyState::none();
    }

    public function set(int $tenantId): void
    {
        $this->transition(TenancyState::tenant($tenantId));
    }

    public function system(string $reason): void
    {
        $this->transition(TenancyState::system($reason));
    }

    public function clear(): void
    {
        $this->transition(TenancyState::none());
    }

    public function restore(TenancyState $state): void
    {
        $this->transition($state);
    }

    public function state(): TenancyState
    {
        return $this->state;
    }

    public function tenantId(): ?int
    {
        return $this->state->tenantId;
    }

    public function isSystem(): bool
    {
        return $this->state->mode === TenancyMode::System;
    }

    /** @throws MissingTenantContext */
    public function requireTenantId(): int
    {
        return $this->state->tenantId ?? throw MissingTenantContext::required();
    }

    /**
     * Run a callback as one workspace, restoring the previous context afterwards (even on error).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(int $tenantId, callable $callback): mixed
    {
        return $this->within(TenancyState::tenant($tenantId), $callback);
    }

    /**
     * Run a callback in the system context (sees every workspace), then restore the previous one.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runAsSystem(string $reason, callable $callback): mixed
    {
        return $this->within(TenancyState::system($reason), $callback);
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function within(TenancyState $state, callable $callback): mixed
    {
        $previous = $this->state;
        $this->transition($state);

        try {
            return $callback();
        } finally {
            $this->transition($previous);
        }
    }

    private function transition(TenancyState $state): void
    {
        $this->state = $state;
        $this->binder->sync($state);
    }
}
