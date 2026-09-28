<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

enum TenancyMode: string
{
    /** No context: tenant-owned models refuse to load and row-level security returns nothing. */
    case None = 'none';

    /** Acting for one workspace: every tenant-owned query is limited to it. */
    case Tenant = 'tenant';

    /** Explicit, audited platform context (staff tools, the reconciler, migrations). */
    case System = 'system';
}
