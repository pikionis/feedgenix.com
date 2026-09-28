<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Queue;

use App\Domain\Tenancy\TenancyState;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Queue\Job;

/**
 * Carries the workspace from the code that dispatches a job to the job itself.
 *
 * A job dispatched while acting for a workspace runs as that workspace. Anything else runs with no
 * context (a job that needs the system context must ask for it explicitly). When the job ends —
 * success or failure — the previous context is restored: nothing for a queue worker, the
 * dispatching request's own context for the sync driver.
 */
final class QueueTenancy
{
    public const string PAYLOAD_KEY = 'feedgenix_tenant_id';

    /** @var list<TenancyState> */
    private array $previous = [];

    public function __construct(private readonly Container $container) {}

    /** @return array<string, int> */
    public function payload(): array
    {
        $tenantId = $this->context()->tenantId();

        return $tenantId === null ? [] : [self::PAYLOAD_KEY => $tenantId];
    }

    public function enter(Job $job): void
    {
        $context = $this->context();
        $this->previous[] = $context->state();

        $tenantId = $job->payload()[self::PAYLOAD_KEY] ?? null;
        is_int($tenantId) ? $context->set($tenantId) : $context->clear();
    }

    public function leave(): void
    {
        $previous = array_pop($this->previous);
        $context = $this->context();

        $previous === null ? $context->clear() : $context->restore($previous);
    }

    private function context(): TenantContext
    {
        return $this->container->make(TenantContext::class);
    }
}
