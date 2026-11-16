# FeedGenix

**AI feed intelligence for e-commerce catalogues.** A seller connects a product feed in whatever
shape their shop exports it. FeedGenix maps it to a canonical schema with AI, translates it into the
languages the seller chooses, checks the result and publishes a feed URL that marketplaces and ad
channels can pull. Prices, stock and barcodes pass through untouched.

It is a multi-tenant SaaS: Laravel 13 and PostgreSQL 18 behind a React 19 single-page app, with every
model call going through one metered AI gateway.

> **Status:** private beta at [feedgenix.com](https://feedgenix.com). Built: identity and two-factor
> sign-in, tenancy, plans and entitlements, ingest (upload, URL, FTP/FTPS, SFTP), the AI gateway and
> credit ledger, AI mapping, catalogue sync, the translation engine, export builds and feed URLs,
> notices and the staff area. Designed but not built yet: Stripe billing
> ([ADR 0008](docs/adr/0008-stripe-billing.md)).

## Contents
- [What it does](#what-it-does)
- [The pipeline](#the-pipeline)
- [Engineering highlights](#engineering-highlights)
- [Stack](#stack)
- [Repository layout](#repository-layout)
- [Getting started](#getting-started)
- [Checks](#checks)
- [Deploys and operations](#deploys-and-operations)
- [Decisions](#decisions)
- [Author](#author)

## What it does

| Step | What the seller sees | What happens underneath |
|---|---|---|
| **Connect** | Upload a file, or give a URL, FTP or SFTP address | The feed is recognised by content, unpacked, converted to UTF-8 and profiled column by column |
| **Map** | A proposed mapping to review, with a reason per column | Heuristics draft it for free; the AI then suggests per column, and every change passes one validation pipeline |
| **Translate** | A plan: which text fields, into which languages, at what estimated cost | Texts are cut into work units, translated through the AI gateway, checked and written only if the source has not changed |
| **Publish** | A stable feed URL per channel and language | A checked CSV or TSV build, verified gzip, immutable files behind a pointer |

Formats read: CSV with any delimiter and encoding, Google Merchant RSS, custom XML, JSON and XLSX,
plain or inside gzip and zip.

## The pipeline

```mermaid
flowchart LR
    A[Feed source<br/>upload · URL · FTP · SFTP] --> B[Fetch<br/>SSRF policy · host key check]
    B --> C[Read<br/>sniff · inflate · UTF-8 · profile]
    C --> D[Map<br/>heuristics + AI per column]
    D --> E[Catalogue sync<br/>canonical products and texts]
    E --> F[Translation plan<br/>work units · reservations]
    F --> G[AI gateway<br/>policy · credits · retry · record]
    G --> H[Quality checks<br/>fenced writes]
    H --> I[Export build<br/>dialects · presets · checks]
    I --> J[Published feed URL]
```

A run moves through explicit states (`queued → fetch → analyse → map → sync → plan → translate →
export → published | unchanged | failed`), with one unfinished run per feed. PostgreSQL is the
authority on what is running; the queue only carries the work.

## Engineering highlights

**One AI gateway, and nothing goes around it.** `App\Domain\AI\AiClient` is the only way to reach a
model. A call passes a decorator chain: policy gate, reservation gate, router, circuit breaker,
retry with backoff that honours `Retry-After`, a recorder that writes one row per attempt, then the
provider (Gemini, Claude, or a fake in tests). An HTTP egress guard and an architecture test both
fail if any other code talks to a model host. See [ADR 0004](docs/adr/0004-ai-gateway.md) and
[ADR 0017](docs/adr/0017-model-providers.md).

**Structured output that cannot drift.** Every item sent to a model carries an id and the response
follows a schema. If the ids that come back do not match exactly, the unit is split in two and
retried, down to six levels, and then fails visibly. An answer is never guessed onto a product.

**Credits are reserved before paid work.** The worst-case cost of a call is reserved first, the
actual cost is settled afterwards and the rest released. No reservation, no call. A unit that runs
out of credits is held and resumes by itself after a top-up.
See [ADR 0005](docs/adr/0005-metering-credits-and-pricing.md) and
[ADR 0016](docs/adr/0016-credit-ledger-and-the-beta-ai-budget.md).

**Golden fields never reach a prompt.** SKU, GTIN, MPN, prices, currency, stock, weights and
dimensions pass through byte-identical. Protected columns are detected by name and by value, and
their values are never shown to a model or used in a composed text.
See [ADR 0018](docs/adr/0018-mapping.md).

**Empty rather than wrong.** When a mapping or a translation is unsure, the field stays empty with
the reason, and the export leaves the cell empty instead of substituting another language.

**Tenancy that fails closed.** Every tenant table has `tenant_id` and a PostgreSQL row-level
security policy. With no tenant context, a query returns nothing. The application's database role is
never `SUPERUSER` or `BYPASSRLS`, in production and in CI alike, and a schema test fails if a tenant
table has no policy. See [ADR 0002](docs/adr/0002-tenancy-and-row-level-security.md).

**Work units with leases instead of healer crons.** Translation work is claimed per workspace with
`FOR UPDATE SKIP LOCKED` and a lease token, so a zombie worker cannot write. One reconciler runs
every minute, requeues expired leases and advances finished runs. It never fetches feeds and never
calls AI. See [ADR 0006](docs/adr/0006-pipeline-work-units-and-reconciler.md) and
[ADR 0020](docs/adr/0020-translation-engine.md).

**Fetching untrusted URLs safely.** Customer-supplied addresses go through an SSRF policy: allowed
schemes and ports, every resolved address must be public unicast, the connection is pinned to the
address that was checked (no DNS rebinding), and each redirect is re-checked. SFTP sends no login
until the server has proved its host key.
See [ADR 0013](docs/adr/0013-fetching-feed-urls.md) and
[ADR 0032](docs/adr/0032-sftp-and-the-server-key.md).

**Reading feeds without losing data.** Recognition is by magic bytes, never by file name. Text is
converted to UTF-8 while streaming, large XLSX and JSON files are streamed instead of loaded whole,
and a half-read file is refused instead of imported. See [ADR 0012](docs/adr/0012-reading-feeds.md).

**Evals before a prompt or model changes.** Prompts are versioned (`map@1`, `translate@1`) and
`php artisan` ships a mapping eval command that scores recorded cases, so a change is measured before
it ships.

**Tests that never reach the network.** Backend tests block stray HTTP requests and run on a real
PostgreSQL with row-level security on. Frontend tests use MSW and fail on any unhandled request. AI
is always the fake provider in tests and end-to-end runs. See [ADR 0010](docs/adr/0010-test-policy.md).

## Stack

- **Backend:** Laravel 13, PHP 8.3+, PostgreSQL 18 (+ pgvector), Redis on servers, Sanctum SPA auth,
  Scramble for the OpenAPI document.
- **Frontend (`frontend/`):** React 19, React Router 8 (framework mode, SPA + pre-rendered marketing
  pages), Vite 8, Tailwind 4, TanStack Query, openapi-fetch with types generated from the API.
- **AI:** Gemini over HTTP and Claude through the official SDK, both behind the gateway.
- **Quality gates:** Pint, Larastan level 8 with no baseline, Rector, Pest (architecture, unit and
  feature tests on PostgreSQL; 600+ tests), TypeScript strict, oxlint, Prettier, Vitest + MSW,
  Playwright, gitleaks.
- **CI/CD:** GitHub Actions for the gates, the API contract check and the staging deploy.

## Repository layout

```
app/Domain/<Module>/     Tenancy · Identity · Billing · Metering · Credits · Feeds · Ingest ·
                         Mapping · Catalog · Translation · Quality · Export · Notices · AI · Ops
app/Http/Controllers/    App · Admin · Auth · Public (thin, final controllers)
frontend/src/            routes · features · components · lib
docs/adr/                one record per decision that is expensive to reverse
ops/                     provisioning, deploy and health-check scripts, with their own tests
design/                  design rules and logos
```

Modules talk to each other through actions, events and a few interfaces (`AiClient`, `RowReader`,
`Fetcher`, `ArtifactServer`). Pest architecture tests enforce the boundaries.

## Getting started
Requirements: PHP 8.3+ with `pdo_pgsql`, `intl`, `zip`; Composer 2; Node 24; PostgreSQL 18.

```bash
# database (as a PostgreSQL superuser; the app role must NOT be superuser or BYPASSRLS)
psql -U postgres -c "CREATE ROLE feedgenix LOGIN PASSWORD '<choose one>' CREATEDB NOSUPERUSER NOBYPASSRLS;"
psql -U postgres -c "CREATE DATABASE feedgenix OWNER feedgenix;" -c "CREATE DATABASE feedgenix_test OWNER feedgenix;" -c "CREATE DATABASE feedgenix_e2e OWNER feedgenix;"

# backend
composer install
cp .env.example .env            # set DB_PASSWORD
php artisan key:generate
php artisan migrate
php artisan serve               # http://127.0.0.1:8000
php artisan queue:work          # imports, catalogue reads and translations run on the queue
php artisan schedule:work       # ops:reconcile every minute: leases, holds, waiting files

# frontend (proxies /api to :8000, same origin)
cd frontend
npm ci
npm run dev                     # http://localhost:5173
```

No model key is needed to run it locally: the fake AI provider answers in development and in tests.

## Checks
```bash
composer check                  # Pint · Larastan · Rector · Pest
cd frontend && npm run check    # tsc · oxlint · Prettier · Vitest · build
cd frontend && npm run e2e      # Playwright against the production build (own database: feedgenix_e2e)
```

After changing an API endpoint: `composer api:export && (cd frontend && npm run api:types)`. CI fails
if the committed OpenAPI document or the generated types are out of date.

## Deploys and operations

CI builds one artifact and hands it to a forced-command deploy receiver on the server, which
switches release directories by symlink and switches back if the smoke test fails. Nothing is ever
pulled with git on a server. Services run as sandboxed systemd units. `ops/provision.sh` is
idempotent and starts with a dry run, and the provisioning scripts are rehearsed in CI on a fresh
Ubuntu 24.04 VM. The runbook is [`ops/README.md`](ops/README.md).

## Decisions

The reasoning behind the design is written down as 32 architecture decision records in
[`docs/adr/`](docs/adr/README.md). FeedGenix is a from-scratch rebuild of the proven core of an
earlier application; [`docs/porting-map.md`](docs/porting-map.md) lists what was ported and the 23
defects fixed on the way, and [`docs/legacy-lessons.md`](docs/legacy-lessons.md) what was learned.

## Author

Built by **Petros Pikionis**, AI Product Engineer.
[onehublabs.com](https://onehublabs.com) ·
[LinkedIn](https://www.linkedin.com/in/petros-platon-pikionis-4045a782/)
