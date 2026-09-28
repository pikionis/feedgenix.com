<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Database;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Row-level security for a tenant-owned table, used from migrations (ADR 0002).
 *
 * FORCE makes the policy apply to the table owner too. WITH CHECK means a row can only be written
 * for the workspace in context. With no context the predicate is false: no rows, no writes.
 */
final class TenantRls
{
    public const string POLICY = 'tenant_isolation';

    public const string PREDICATE = "current_setting('app.bypass', true) = 'on'"
        ." OR tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint";

    public static function enable(string $table): void
    {
        $table = self::identifier($table);

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement(sprintf(
            'CREATE POLICY %s ON %s USING (%s) WITH CHECK (%s)',
            self::POLICY,
            $table,
            self::PREDICATE,
            self::PREDICATE,
        ));
    }

    public static function disable(string $table): void
    {
        $table = self::identifier($table);

        DB::statement(sprintf('DROP POLICY IF EXISTS %s ON %s', self::POLICY, $table));
        DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
    }

    private static function identifier(string $table): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]{0,62}$/', $table) !== 1) {
            throw new InvalidArgumentException("Not a plain table name: [{$table}].");
        }

        return '"'.$table.'"';
    }
}
