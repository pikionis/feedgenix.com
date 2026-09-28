# ADR 0002 — Tenancy and PostgreSQL row-level security

- Status: accepted
- Date: 2026-09-28

## Context
Every customer is a tenant (workspace). A cross-tenant leak is the worst possible bug: one seller
seeing another's catalogue, translations or costs. The legacy app added tenancy late, with a global
scope whose empty context meant "no filter" — it failed open.

## Decision
- **Every tenant-owned table has `tenant_id NOT NULL`** (foreign key, leading every composite index).
  No "tenant via parent" scoping.
- **Two layers.** The Eloquent `BelongsToTenant` global scope is the first; PostgreSQL row-level
  security is the second, so raw SQL, reports and forgotten scopes are covered too.
- **Policy** (a migration macro turns on `ENABLE` and `FORCE` for every tenant table):
  ```sql
  USING (current_setting('app.bypass', true) = 'on'
         OR tenant_id = NULLIF(current_setting('app.tenant_id', true), '')::bigint)
  ```
- **Fail closed.** With no tenant context the policy matches zero rows. `TenantContext::system()`
  (staff/admin, reconciler) is explicit and audited, never a default.
- **Session variables, not `SET LOCAL`.** They are set with `set_config(…, false)` at the start of
  every request (middleware before `SubstituteBindings`) and every job (a `JobProcessing` listener),
  because streaming jobs span many transactions and queue workers reuse connections. They are reset
  when the request or job ends.
- **Database roles.** The application role is never SUPERUSER or BYPASSRLS (a test asserts it). In
  production, migrations run as an owner role and the application as a DML-only role; `FORCE` keeps
  the owner subject to the policies too.
- **No PgBouncer transaction pooling** (it would break session variables).
- A schema test fails if any table with a `tenant_id` column lacks the policy.

## Consequences
- A missing context shows up as empty results in tests, not as leaked data in production.
- Staff tooling must switch to `system()` explicitly; "view as tenant" is read-only and audited.
- Route sweeps (foreign ids → 404) and raw-SQL tests are part of the tenancy suite from M1.
