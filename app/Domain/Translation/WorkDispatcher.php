<?php

declare(strict_types=1);

namespace App\Domain\Translation;

use App\Domain\Billing\Entitlements;
use App\Domain\Tenancy\TenantContext;
use App\Domain\Translation\Jobs\TranslateUnitJob;
use App\Domain\Translation\Models\WorkUnit;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Hands pending work units to workers (ADR 0006): per workspace, at most its plan's worker slots
 * at a time, claimed with FOR UPDATE SKIP LOCKED so two pumps never take the same unit. A lease
 * fences off a worker that outlives it. It runs when work is created or finishes, and every minute.
 */
final readonly class WorkDispatcher
{
    public const int LEASE_MINUTES = 15;

    public function __construct(private TenantContext $context) {}

    /** @param int|null $tenantId one workspace, or every workspace with pending work */
    public function pump(?int $tenantId = null): int
    {
        $tenants = $tenantId !== null ? [$tenantId] : $this->context->runAsSystem('find workspaces with pending AI work', static fn (): array => WorkUnit::query()
            ->where('status', 'pending')
            ->where(static fn (Builder $q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->distinct()->pluck('tenant_id')->map(static fn (mixed $value): int => (int) $value)->all());

        $dispatched = 0;
        foreach ($tenants as $tenant) {
            $dispatched += $this->context->run($tenant, fn (): int => $this->pumpOne($tenant));
        }

        return $dispatched;
    }

    private function pumpOne(int $tenantId): int
    {
        $slots = Entitlements::for($tenantId)->limit('worker_slots') ?? 8;
        $leased = WorkUnit::query()->where('status', 'leased')->where('lease_until', '>', now())->count();
        $free = $slots - $leased;
        if ($free <= 0) {
            return 0;
        }

        // The application's clock, not the database's: leases and backoffs are set with it.
        $now = now();
        $claimed = DB::select(<<<'SQL'
            UPDATE ai_work_units
            SET status = 'leased', lease_token = gen_random_uuid(), lease_until = ?, attempts = attempts + 1, updated_at = ?
            WHERE id IN (
                SELECT id FROM ai_work_units
                WHERE status = 'pending' AND (available_at IS NULL OR available_at <= ?)
                ORDER BY id
                LIMIT ?
                FOR UPDATE SKIP LOCKED
            )
            RETURNING id, lease_token
            SQL, [$now->copy()->addMinutes(self::LEASE_MINUTES), $now, $now, $free]);

        foreach ($claimed as $row) {
            $values = (array) $row;
            // A block closure: the job is queued while the workspace context is set.
            (static function () use ($values, $tenantId): void {
                dispatch(new TranslateUnitJob((int) $values['id'], $tenantId, (string) $values['lease_token']));
            })();
        }

        return count($claimed);
    }
}
