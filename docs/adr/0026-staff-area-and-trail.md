# ADR 0026 — The staff area and its trail

- Status: accepted
- Date: 2026-10-28
- Refines: ADR 0002 (tenancy), ADR 0005 (metering and pricing), ADR 0016 (credit ledger)

## Context
Running the private beta needs a view across workspaces: who signed up, on which plan, what they
use, what waits and why, and what the AI cost them in credits. ADR 0002 said staff tooling switches
to the system context explicitly and that looking into a workspace is audited. Until now the staff
area had one page of counts and the trail was only a reason string in the request's context.

## Decision
- **One line per staff request.** `staff_audit` holds who (id and email), the method and path, the
  HTTP status the request got, the workspace it was about and, for a change, what it changed. The
  staff middleware writes the line once the request has its answer, so a refused or failed request
  is recorded too. A change names itself and its details with `StaffAudit::describe()`; a change that
  does not is still recorded, as `change`.
- **The trail cannot be edited.** A trigger rejects every `UPDATE` and `DELETE`, and the table has
  no foreign keys: it outlives the accounts and workspaces it names. It is a platform table (no
  `tenant_id`, no row-level security); only the staff area reads it.
- **Staff see credits and tokens, never a cost.** CLAUDE.md rule 6 holds for staff pages too: the
  workspace list and detail show AI spend in credits and tokens; `cost_usd`, the markup and provider
  prices are never selected. A test scans every key and value of the staff responses.
- **A workspace is read in its own context.** The detail reads the workspace's rows through
  `TenantContext::run()`, so row-level security keeps another workspace's feeds, ledger and
  notices out even if a query forgets its filter. The list's figures are counted in one query in the
  system context.
- **Only digits reach the database as a workspace id**: PostgreSQL answers a word or a 20-digit
  number with an error, which would be a 500, not a 404.

## Consequences
- Every staff action that follows (grant credits, change a plan, read-only "view as") goes through
  the same middleware and describes itself; none needs its own logging.
- The trail grows with every staff page view; at beta scale that is small. Pruning, if ever needed,
  is a migration that drops the trigger, deliberately and visibly.
