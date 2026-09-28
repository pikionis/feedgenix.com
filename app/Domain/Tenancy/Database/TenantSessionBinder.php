<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Database;

use App\Domain\Tenancy\TenancyMode;
use App\Domain\Tenancy\TenancyState;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;

/**
 * Mirrors the tenant context into the PostgreSQL session variables that the row-level security
 * policies read: app.tenant_id and app.bypass (ADR 0002).
 *
 * They are session settings (set_config(..., false)), not SET LOCAL, because streaming jobs span
 * many transactions. Two things can silently change them, and both are handled by re-applying the
 * current context: a new or re-established connection starts without them, and a rolled-back
 * transaction reverts any change made inside it (which could otherwise bring back a previous
 * job's workspace).
 */
final class TenantSessionBinder
{
    /** @var array<string, string> connection name => state key currently set in that session */
    private array $applied = [];

    public function __construct(private readonly DatabaseManager $db) {}

    /** Make the default connection reflect this state (no query when it already does). */
    public function sync(TenancyState $state): void
    {
        $this->apply($this->db->connection(), $state, force: false);
    }

    /** A new or re-established connection: its session has no settings yet. */
    public function connected(Connection $connection, TenancyState $state): void
    {
        if ($state->mode === TenancyMode::None) {
            // Unset settings already mean "no context" to the policies; skip the round trip.
            $this->applied[$this->name($connection)] = $state->key();

            return;
        }

        $this->apply($connection, $state, force: true);
    }

    /** A rollback may have reverted settings changed inside the transaction: set them again. */
    public function rolledBack(Connection $connection, TenancyState $state): void
    {
        $this->apply($connection, $state, force: true);
    }

    private function apply(Connection $connection, TenancyState $state, bool $force): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        $name = $this->name($connection);
        if (! $force && ($this->applied[$name] ?? null) === $state->key()) {
            return;
        }

        $connection->select(
            "select set_config('app.tenant_id', ?, false), set_config('app.bypass', ?, false)",
            [
                $state->tenantId === null ? '' : (string) $state->tenantId,
                $state->mode === TenancyMode::System ? 'on' : 'off',
            ],
        );

        $this->applied[$name] = $state->key();
    }

    private function name(Connection $connection): string
    {
        return (string) $connection->getName();
    }
}
