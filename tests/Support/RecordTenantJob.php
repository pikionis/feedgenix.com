<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tenancy\TenancyMode;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Records the tenant context (and the database session) a queued job actually runs with. */
final class RecordTenantJob implements ShouldQueue
{
    use Queueable;

    /** @var list<array{mode: TenancyMode, tenant: int|null, session: string|null}> */
    public static array $seen = [];

    public function __construct(public readonly bool $fail = false) {}

    public function handle(TenantContext $context): void
    {
        $session = DB::selectOne("select current_setting('app.tenant_id', true) as value")?->value;

        self::$seen[] = [
            'mode' => $context->state()->mode,
            'tenant' => $context->tenantId(),
            'session' => is_string($session) ? $session : null,
        ];

        if ($this->fail) {
            throw new RuntimeException('job failed on purpose');
        }
    }
}
