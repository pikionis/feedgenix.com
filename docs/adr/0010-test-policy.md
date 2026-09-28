# ADR 0010 — Test policy

- Status: accepted
- Date: 2026-09-28

## Context
The product rewrites customers' catalogues with AI and charges them for it. The legacy app's
incidents came from paths nobody had tested adversarially: parallel writes, interrupted scans,
renumbered model output, silent gzip truncation, tests that quietly reached real APIs.

## Decision
- **PostgreSQL only.** Tests run on PostgreSQL (never SQLite) so RLS, `citext`, JSONB and locking
  behave as in production. `pest --parallel` gives each process its own database.
- **No network, ever.** Backend: `Http::preventStrayRequests()` in the base test case. Frontend: MSW
  with `onUnhandledFrame: 'error'`, and the API client resolves `fetch` on every call so nothing can
  bypass MSW. AI is the fake provider under the real gateway in tests and in e2e.
- **Adversarial by default.** Every change ships with tests for its edge cases and failure paths.
  Every guard is proven once by breaking the code and watching its test fail. Every ported legacy
  defect gets a named test that fails on the legacy logic.
- **Invariant suites** (grow with the milestones): golden values byte-identical in every dialect;
  cross-tenant ids return 404 and RLS blocks raw SQL; disappear and shrink guards; manual edits
  survive; every stage is idempotent; concurrent admission and reservations never exceed limits;
  no response exposes cost or markup.
- **Architecture tests:** module boundaries, AI egress only through the gateway, strict types, final
  controllers, and the banned-token scan (legacy coupling stays out of product code).
- **Static analysis:** Larastan level 8 with no baseline; Rector as a dry-run gate; TypeScript strict
  with `noUncheckedIndexedAccess`; oxlint with warnings denied.
- **Golden files** change only through an explicit command, never by re-recording on failure.
- **End-to-end:** Playwright against the production build served like nginx serves it, with Laravel
  on the same origin.
- **Money code** (M7): mutation score ≥ 90% on the ledger.
- **Every change runs both gates** (`composer check`, `npm run check`) and the commit message or
  report cites the counts.

## Consequences
- CI is the same set of gates as local, plus the API-contract drift check, e2e and gitleaks.
- A red suite blocks the next step; there are no known-failing tests.
