# CLAUDE.md — FeedGenix

FeedGenix is a multi-tenant SaaS for e-commerce product feeds. A seller connects a feed; FeedGenix
maps it to a canonical schema with AI, translates it into any language, checks the quality and
publishes a feed URL. It is built from scratch (started 2026-09-28) and ports only the proven core of
the legacy Feed Intelligence app. The decisions behind it are in `docs/adr/`; the porting plan is
`docs/porting-map.md`; lessons from the legacy app are in `docs/legacy-lessons.md`.

## Rules that never bend
1. **Golden rule.** Golden fields (SKU, GTIN/EAN, MPN, prices, currency, stock, weights, dimensions)
   pass through byte-identical. They never reach a prompt, a composition or a value map.
2. **Empty rather than wrong.** When the AI or a mapping is unsure, the field stays empty.
3. **Tenancy fails closed.** Every tenant table has `tenant_id` and PostgreSQL row-level security.
   A missing tenant context returns nothing, never everything. The app DB role is never SUPERUSER
   or BYPASSRLS.
4. **All AI goes through the AI gateway** (`App\Domain\AI`). No other code talks to a model host.
5. **Reserve before paid work.** Credits are reserved before any AI call; the actual cost is settled
   after and the rest released. No reservation, no call.
6. **Pricing rule (owner):** every AI call, on every model, is charged at exactly provider cost × 1.1
   (billed tokens including thinking tokens, every billed attempt, current FX). **Cost, the markup and
   provider prices never appear in any response, page or email.**
7. **Tests never reach the network.** Backend: `Http::preventStrayRequests()`. Frontend: MSW with
   `onUnhandledFrame: 'error'`. AI is always the fake provider in tests and e2e.

## Stack and commands
- Laravel 13 at the repo root (PHP 8.3 locally, 8.4 on servers; code must stay 8.3-compatible),
  PostgreSQL 18 (pgvector on servers/CI), Sanctum SPA auth, Scramble for the OpenAPI document.
- `frontend/`: React 19, React Router 8 framework mode (`ssr: false`, `/` pre-rendered), Vite 8,
  Tailwind 4, shadcn/ui conventions, TanStack Query, openapi-fetch, TypeScript 5.9 (not 7: the
  OpenAPI type generator still needs the TypeScript 5 API).
- Gates (run before every commit; CI runs the same):
  - `composer check` → Pint, Larastan level 8 (no baseline), Rector dry-run, Pest (parallel, on PostgreSQL).
  - `cd frontend && npm run check` → typegen + tsc, oxlint, Prettier, Vitest, build.
  - `cd frontend && npm run e2e` → Playwright against the production build (starts Laravel on :8000 with
    its own freshly migrated database, `feedgenix_e2e`, and open registration).
- API contract: `composer api:export` then `cd frontend && npm run api:types`. CI fails if
  `frontend/openapi.json` or `frontend/src/lib/api/schema.d.ts` is out of date.
- Local database: role `feedgenix` (not superuser, CREATEDB for parallel tests), databases
  `feedgenix`, `feedgenix_test` and `feedgenix_e2e`. Every session runs in UTC (config/database.php). Queue, cache and sessions use the database driver locally
  (there is no Redis locally); code uses only Laravel's contracts, never Redis directly.

## Structure
- `app/Domain/<Module>/{Models,Actions,Jobs,Data,Events,Exceptions}` — modules: Tenancy, Identity,
  Billing, Metering, Credits, Feeds, Ingest, Mapping, Catalog, Translation, Quality, Export, Agent,
  AI, Ops. Modules talk through Actions, events and a few interfaces (AiClient, VectorIndex,
  ArtifactServer, RowReader, Fetcher); Pest arch tests enforce it.
- `app/Http/Controllers/{App,Admin,Auth,Public}` — thin, final controllers.
- `frontend/src/{routes,features,components,lib}` — `~/` is `frontend/src/`.
- `design/` — design rules and logos; the tokens live in `frontend/src/styles/tokens.css`.
- `docs/adr/` — one ADR per irreversible decision. Write a new one rather than editing an accepted one.

## How to port legacy code
The legacy repo (`../feed-intelligence`, branch `feat/saas-tenancy`) and the April prototype
(`../feedgenix-prototype-2026-04`) are **read-only references**. Their CLAUDE.md files do not apply
here. **Never open the legacy memory folder, any `.env` or `settings.local.json`** — they hold
production credentials. Never copy customer data; fixtures are synthetic.

For each legacy file: write a port card in `docs/porting-map.md` (what is kept, which defect is
fixed, what is dropped) → write failing adversarial tests (one named test per defect, failing on the
legacy logic) → port it as typed code in its module → run both gates and cite the counts.

**Banned in product code** (an arch test scans app, config, database, routes, resources and
frontend/src): Channable French column names (`titre_fr`, `categorie_de`…), `_meta_` columns,
`id_type_produit`, legacy supplier names, and the hard-coded `fr/de/en/it` language list. Languages
are BCP-47 codes chosen per workspace.

## Tenancy in code (ADR 0002)
- Tenant-owned models use `App\Domain\Tenancy\Concerns\BelongsToTenant`; their migration calls
  `TenantRls::enable('<table>')`. A schema test fails if a `tenant_id` table has no policy.
- Act for a workspace with `TenantContext::run($tenantId, fn …)`; platform-wide code uses
  `runAsSystem('<reason>', fn …)`. With no context, tenant models throw and raw SQL sees nothing.
- Inside `run()`, dispatch jobs from a **block** closure (`function () { dispatch($job); }`), never
  `fn () => dispatch($job)`: a returned `PendingDispatch` queues the job when it is destroyed, which
  is after the context has been restored — the job would run without its workspace.
- Queued jobs inherit the dispatcher's workspace automatically; they never inherit the system context.
- Models declare their database defaults in `$attributes`: strict models (outside production) throw
  on a missing attribute instead of reading null.

## How to work
- Every change ships with adversarial tests (edge cases, failure paths, "would this test fail on the
  old code?"). Prove a guard works by breaking the code once and watching the test fail.
- Run the full gates after every change and cite passed / assertions / duration.
- Commit after each coherent step. Conventional commit messages. Never skip hooks.
- Preview or dry-run before any heavy or AI-spending work.
- One code path per behaviour; no parallel "legacy" paths.
- Keep the docs current (ADRs, porting map, lessons, README) in the same commit as the change.
- The owner speaks Greek; code, docs and commit messages are in English.

## Environments
- Private beta/staging runs on the existing VPS next to the frozen legacy app, strictly isolated.
  It has its own user, PostgreSQL, Redis, PHP-FPM master and nginx vhost, all sandboxed systemd
  units (ADR 0009, ADR 0011). **Every server change needs the owner's explicit go-ahead at that
  moment.** Production moves to a new server at launch.
- `ops/`:
  - `survey.sh` is read-only;
  - `provision.sh` is idempotent and always starts with `--dry-run`;
  - `feedgenix-check` runs after every change and every deploy.

  The runbook is `ops/README.md`. Gates for ops changes: shellcheck, `ops/tests/unit.sh`, and the
  CI rehearsal (`ops/tests/rehearse.sh`) on a fresh Ubuntu 24.04 VM.
- Never `git pull` on a server. CI builds one artifact and hands it to the forced-command deploy
  receiver, which switches release directories by symlink and switches back if the smoke test fails.
