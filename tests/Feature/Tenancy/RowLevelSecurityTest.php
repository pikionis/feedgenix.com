<?php

declare(strict_types=1);

use App\Domain\Tenancy\Database\TenantRls;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Tables that carry tenant_id but are read before a context exists, with the reason. */
const RLS_EXEMPT = [
    'tenant_user' => 'membership: resolving which workspace a user may act in happens before any context',
];

function sessionSetting(string $name): ?string
{
    $value = DB::selectOne('select current_setting(?, true) as value', [$name])?->value;

    return is_string($value) ? $value : null;
}

/** @return list<string> */
function rawLanguageCodes(): array
{
    return array_map(
        static fn (object $row): string => $row->language_code,
        DB::select('select language_code from tenant_languages order by language_code'),
    );
}

it('runs as a database role that can never bypass row-level security', function (): void {
    $role = DB::selectOne('select rolsuper, rolbypassrls from pg_roles where rolname = current_user');

    expect($role->rolsuper)->toBeFalse()->and($role->rolbypassrls)->toBeFalse();
});

it('exempts exactly the same tables in feedgenix-check on the server', function (): void {
    $script = (string) file_get_contents(base_path('ops/check.sh'));
    preg_match('/^RLS_EXEMPT_TABLES=\(([^)]*)\)$/m', $script, $matches);
    $onServer = preg_split('/\s+/', trim($matches[1] ?? ''), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    sort($onServer);
    $here = array_keys(RLS_EXEMPT);
    sort($here);

    expect($onServer)->toBe($here);
});

it('forces the tenant policy on every table that holds workspace data', function (): void {
    $tables = DB::select(
        "select table_name from information_schema.columns where table_schema = 'public' and column_name = 'tenant_id' order by table_name",
    );
    expect($tables)->not->toBeEmpty();

    foreach ($tables as $row) {
        if (array_key_exists($row->table_name, RLS_EXEMPT)) {
            continue;
        }

        $flags = DB::selectOne(
            "select relrowsecurity, relforcerowsecurity from pg_class where relname = ? and relnamespace = 'public'::regnamespace",
            [$row->table_name],
        );
        $policies = DB::selectOne(
            "select count(*) as n from pg_policies where schemaname = 'public' and tablename = ? and policyname = ?",
            [$row->table_name, TenantRls::POLICY],
        );

        expect([$row->table_name, $flags->relrowsecurity, $flags->relforcerowsecurity, (int) $policies->n])
            ->toBe([$row->table_name, true, true, 1]);
    }
});

it('returns no rows to raw SQL without a context, only the workspace rows with one, and all in the system context', function (): void {
    [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
    addLanguage($a, 'de');
    addLanguage($b, 'fr');

    tenancy()->clear();
    expect(rawLanguageCodes())->toBe([]);

    tenancy()->set($a->id);
    expect(rawLanguageCodes())->toBe(['de']);

    tenancy()->set($b->id);
    expect(rawLanguageCodes())->toBe(['fr']);

    tenancy()->system('test');
    expect(rawLanguageCodes())->toBe(['de', 'fr']);
});

it('refuses to write a row for another workspace, even with raw SQL', function (): void {
    [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
    tenancy()->set($a->id);

    expect(fn () => DB::insert(
        'insert into tenant_languages (tenant_id, language_code, created_at, updated_at) values (?, ?, now(), now())',
        [$b->id, 'it'],
    ))->toThrow(QueryException::class, 'row-level security');
});

it('refuses writes when there is no context at all', function (): void {
    $a = Tenant::factory()->create();
    tenancy()->clear();

    expect(fn () => DB::insert(
        'insert into tenant_languages (tenant_id, language_code, created_at, updated_at) values (?, ?, now(), now())',
        [$a->id, 'it'],
    ))->toThrow(QueryException::class, 'row-level security');
});

it('puts the right workspace back after a rollback reverts the session setting', function (): void {
    [$a, $b] = [Tenant::factory()->create(), Tenant::factory()->create()];
    addLanguage($a, 'de');
    addLanguage($b, 'fr');

    tenancy()->set($a->id);
    DB::beginTransaction();
    tenancy()->set($b->id);   // changed inside the transaction…
    DB::rollBack();           // …which PostgreSQL reverts to workspace A

    // The context still says B, so the session must say B too — never A's rows.
    expect(sessionSetting('app.tenant_id'))->toBe((string) $b->id)
        ->and(rawLanguageCodes())->toBe(['fr']);
});

it('sets the context again on a new database connection', function (): void {
    $a = Tenant::factory()->create();
    tenancy()->set($a->id);

    DB::reconnect();

    expect(sessionSetting('app.tenant_id'))->toBe((string) $a->id)
        ->and(sessionSetting('app.bypass'))->toBe('off');
});

it('clears the bypass when leaving the system context', function (): void {
    tenancy()->system('test');
    expect(sessionSetting('app.bypass'))->toBe('on');

    tenancy()->clear();
    expect(sessionSetting('app.bypass'))->toBe('off')->and(sessionSetting('app.tenant_id'))->toBe('');
});

it('only accepts plain table names in the migration helper', function (string $name): void {
    expect(fn () => TenantRls::enable($name))->toThrow(InvalidArgumentException::class);
})->with(['users; drop table users', 'Tenant', '"quoted"', '', 'a-b']);
