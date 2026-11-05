# Porting map — legacy Feed Intelligence → FeedGenix

The legacy app (`../feed-intelligence`, branch `feat/saas-tenancy`, commit `0b22b0c`, backed up on
GitHub and as a git bundle) is a **read-only reference**. Only proven behaviour is ported, as typed
code in its module, with its tests. Nothing is copied blindly.

## Procedure (one port card per file)
1. Add a card below: legacy path, what is kept, which defects are fixed, what is dropped.
2. Write failing adversarial tests first — one named test per defect, failing on the legacy logic.
3. Port into the module as typed code (strict types, Larastan level 8).
4. Run `composer check` and `npm run check`; cite the counts in the commit.

## What goes where

| Legacy (`feat/saas-tenancy@0b22b0c`) | New home | Milestone |
|---|---|---|
| `app/Support/Tenancy/TenantContext`, `BelongsToTenant`, `ResolveTenant`, `EnsureStaff` | `Domain/Tenancy` (+ `system()` context, fail closed, RLS binder; every table carries `tenant_id`, `BelongsToTenantViaSupplier` is dropped) | M1 |
| Auth controllers (register, verify, reset, Google, profile), `Services/Auth/TenantProvisioner`, `WorkspaceController` | `Domain/Identity`, `Http/Controllers/App` | M1 |
| `Services/Billing/{Entitlements,TenantEntitlements,PlanLimitExceeded}`, `config/tiers.php` | `Domain/Billing`, `config/plans.php` (suppliers → feeds) | M1 |
| `PlanLimitsApplier`, `EnsurePlanFeature`, `EnsureFeedActive`, `TenantsSetPlan`, `TenantsSyncPlans` | `Domain/Billing` (applier extended to languages) | M1 / M6 |
| `Support/Uploads/ChunkedUpload` | `Domain/Ingest/Upload` | M2 |
| `FileParser` | `Domain/Ingest/Readers` (league/csv, XMLReader, OpenSpout, json-machine) | M2 |
| `FeedDownloadService`, `CommissionFeedFetcher::ensureUtf8/normalise`, `verifyArchiveIntegrity` | `Domain/Ingest/Fetch` (+ SSRF guard), `GzipInflater` | M2 (SFTP/FTP: M8) |
| `AnalysisService` | `Domain/Ingest/ColumnProfiler` | M2 |
| `ClaudeApiClient`, `GeminiApiClient`, `StructuredJson` | `Domain/AI/Providers` behind the gateway (ADR 0004) | M3 |
| `Observability/{EngineCallLogger,PricingService}`, `config/engine_pricing.php` | `Domain/AI` Recorder, `config/ai.php` | M3 |
| `Support/Tenancy/AiEgressGuard`, `Support/Http/Tls` | `Domain/AI` | M3 |
| `MappingService` (composeFields, sanitizeCompositions, enforceStrictComposition, buildFieldReports, isProtectedColumn, buildCards, reason) | `Domain/Mapping` | M3 |
| `FieldValidator` | `Domain/Mapping/FieldValidator` (Unicode `\p{L}` and script checks) | M3 |
| `SmartScanner` (composeFromRecipe, non-destructive merge, invalidateStaleTranslations, disappear guard) | `Domain/Catalog/{CatalogSyncer,CatalogFinalizer}` — one code path for every sync mode | M4 |
| `DescriptionFormatter`, `ShortDescriptionMaker` | `Domain/Catalog/Formatters` | M4 |
| `TranslationRouter` (geminiTranslateChunkRecursive, bulkCacheLookup, `memory()`, preflight, breaker), `TranslationService` (batch sizing, locks) | `Domain/Translation/{WorkPlanner,UnitExecutor,PromptBuilder,Aligner,TranslationMemory}` | M4 |
| `Quality/{AlignmentMarker,QualityChecker,QualityResult}` | `Domain/Quality` (+ `QualityPolicy`, the one place hard fails are decided) | M4 |
| `ExportService` (atomic publish, shrink guard, validateExport, escapeField, renderSnapshot), `BuildSupplierFeed`, `ExportDownloadController`, `FeedsExportSnapshot` | `Domain/Export` (presets, dialects, builder, publisher, feed tokens) | M5 |
| Frontend `auth.tsx`, `AuthScreens.tsx`, `lib/api.ts` | `frontend/src/features/auth`, `frontend/src/lib/api` | M1 |
| Frontend `components/customer/*`, `pages/customer/*` (CustomerShell, Overview, WorkspaceSettings, PlanCards, UpgradeDialog), `lib/limits.ts` | `frontend/src/features/{workspace,billing}` | M1 / M6 |
| Frontend `components/AddSupplier.tsx` (create feed, import URL/file) | `frontend/src/features/feeds` | M2 |
| Tests `Feature/{Auth,Tenancy,Security,Billing,Observability}`, `Golden/*`, `ManualEditTest`, `MemoryIsolationTest`, `ChunkedUploadSecurityTest`, `PrivilegeEscalationTest` | Ported as behaviour specs next to their code | with their code |
| Normaliser engines and lexicons (`colors`, `color_canonicals`, `color_translations`, `composition_materials`), `CrossLanguageAuditor`, Google taxonomy | BCP-47 JSON seeds (supplier names and supplier typos removed) + `taxonomy:sync` from Google's official files | M12 |

## Legacy defects fixed in the port

| # | Defect | Milestone |
|---|---|---|
| D1 | gz feeds were never decompressed | M2 |
| D2 | encoding `auto` converted nothing | M2 |
| D3 | duplicate CSV headers threw | M2 |
| D4 | namespaced XML fields (`g:id`) were dropped | M2 |
| D5 | XLSX files were loaded into memory whole | M2 |
| D6 | a mapping was reused across re-uploads with different columns | M3 |
| D7 | refine/save bypassed mapping validation | M3 |
| D8 | letter checks were Latin-only | M3 |
| D9 | identical-text language detection deleted short values in every language | M4 |
| D10 | the disappear step in the parallel scan path was unguarded | M4 |
| D11 | the Gemini key travelled in the URL (and leaked through exception messages) | M3 |
| D12 | Gemini renumbering its outputs caused silent misalignment | M4 |
| D13 | 429 responses were never retried | M3 |
| D14 | daily spend was not updated atomically | M3 |
| D15 | checkpoint resume never actually resumed | M4 |
| D16 | re-saving a URL import without credentials wiped the stored credentials | M8 (fixed: ADR 0030) |
| D17 | a stats route was reachable without authentication | M1 |
| D18 | no rate limiting on authentication | M1 |
| D19 | forgot-password revealed whether an account exists | M1 |
| D20 | Google login auto-linked (and verified) an existing password account | M1 |
| D21 | plan and owner fields were mass-assignable | M1 |
| D22 | a QA hard fail still wrote the translated value | M4 |
| D23 | the XML item was the first depth-1 element, which broke RSS feeds (`<rss><channel><item>`) | M2 |
| D24 | the translation-memory key lacked field class, style and prompt version | M4 |

## Never ported
- Filament and Livewire; the Commission/Galaxus module.
- Supplier-specific code: `titre_*` / `categorie_*` columns, `_meta_*`, `id_type_produit`, fixed
  `fr/de/en/it`, supplier-id special cases, the Swiss "ss" rule as a universal rule.
- The frozen-archive machinery and the four overlapping healer crons (replaced by ADR 0006).
- Customer CSVs, database dumps and production data — fixtures are synthetic.
- The legacy Claude memory folder, any `.env`, `settings.local.json` (they hold credentials).

## Port cards

### M1 · `TenantContext`, `BelongsToTenant`, `ResolveTenant`, `EnsureStaff` → `Domain/Tenancy`
- **Kept:** a scoped per-request/per-job context with `run()` that restores on error; tenant resolution
  before route-model binding (foreign ids are 404); staff-only admin guard.
- **Changed / fixed:**
  - The empty context now **fails closed**. Tenant models throw `MissingTenantContext`, and raw SQL sees
    no rows because of RLS. The legacy empty context was unfiltered.
  - The system context is explicit and carries a reason.
  - Staff get **no** bypass inside the customer app.
  - Membership is re-checked on every request.
  - Rows cannot be created for, or moved to, another workspace (`CrossTenantWrite`, plus RLS `WITH CHECK`).
- **New:**
  - PostgreSQL RLS (`TenantRls`) and a session binder that mirrors the context into `app.tenant_id` /
    `app.bypass`. The binder re-applies the context after every rollback (a rollback reverts a setting
    changed inside it, which could bring back a previous job's workspace) and on every new connection.
  - Jobs carry the dispatcher's workspace in their payload and restore the previous context when they end.
- **Dropped:** `BelongsToTenantViaSupplier` (every data table has `tenant_id`); the frozen-archive handling.
- **Proof:** removing the rollback hook, the reconnect hook or the middleware priority each makes exactly
  its test fail.

### M1 · Auth controllers (register, login, verify, reset, Google, profile) → `Http/Controllers/Auth`, `Domain/Identity`
- **Kept:**
  - email sign-up that creates the person's own workspace;
  - SPA cookie sessions (Sanctum);
  - signed email verification;
  - password reset;
  - Google sign-in;
  - profile and password.
- **Fixed:**
  - **D17** — a route sweep fails if any route outside a documented public list lacks authentication, checked both statically and with anonymous calls.
  - **D18** — rate limits:
    - login: 5/min per address+IP and 30/min per IP;
    - sign-up: 5/min and 20/h per IP;
    - reset: 3/min and 20/h.
  - **D19** — the reset request answers identically for every address.
  - **D20** — when Google proves the address of an unverified account, that account's password is removed and its sessions ended before linking. Addresses that Google itself has not verified are refused.
  - **D21** — protected fields cannot be mass assigned.
- **New:**
  - Invite-only registration for the private beta:
    - only a hash of each code is stored;
    - one use, locked in the sign-up transaction;
    - optional binding to one address, and an expiry;
    - every failure gets the same answer;
    - codes are created with `invites:create`.
  - SHA-256 address hash in verification links.
  - A password reset or change ends the other sessions.
  - Production checks passwords against public breaches using k-anonymity.
  - `has_password`, so Google-only accounts *set* a first password rather than change one.
- **Found on the way:**
  - The local PostgreSQL ran in Europe/Bucharest. Every `timestamptz` the app wrote was stored 3 hours off (a reset token was "expired" as soon as it was issued). Every session is now pinned to UTC, and a test checks it.
  - Rector's Laravel 5 upgrade rule silently renamed routes named `auth.login` and `auth.logout` to event classes; that rule is now skipped.

### M1 · `Entitlements`, `TenantEntitlements`, `PlanLimitExceeded`, `config/tiers.php`, `TenantsSetPlan`, `WorkspaceController` → `Domain/Billing`, `config/plans.php`
- **Kept:**
  - the plan in force, with 3 grace days;
  - the yearly annual pool (12×);
  - monthly slices anchored at the allowance clock, with no month-end drift;
  - limit precedence: override > plan > internal guard;
  - the cheapest-fitting upgrade suggestion;
  - the 402 payload `{error, limit, allowed, used, plan, upgrade_to, message}`;
  - a public catalogue without internal guards;
  - `tenants:set-plan` for staff.
- **Changed:**
  - `suppliers` is now `feeds`.
  - The overage is 10 credits per product (1 credit = €0.0001).
  - The legacy USD AI cap becomes the private-beta `beta_ai_budget_eur_month` guard, which is never public.
  - `set-plan` validates every input (plan, interval, real dates, known limits, values) and keeps the start of the term on renewals.
- **New:**
  - Target languages are the first limited resource. Setting them replaces the whole list; codes are matched case-insensitively against the registry and duplicates collapse.
  - Changes are serialised on the workspace row, so concurrent requests cannot exceed the limit.
  - Only owners and admins can change them.
  - The 402 is documented in the OpenAPI contract through a Scramble extension, so the SPA's types include the upgrade payload.
- **Later:** pausing over-limit feeds and languages on a downgrade (M6); feed and product counters (M2 and M4).

### M1 · Frontend `auth.tsx`, `AuthScreens.tsx`, `lib/api.ts`, `lib/limits.ts`, `UpgradeDialog`, `PlanCards`, `CustomerShell`, `WorkspaceSettings` → `frontend/src/{routes,features,lib}`
- **Kept:**
  - sign-in, sign-up (with Google), password reset;
  - the verification banner;
  - one upgrade dialog opened by any 402, using the server's wording and the cheapest plan that fits;
  - plan cards with the monthly/yearly switch;
  - workspace and account settings.
- **Changed:**
  - A typed `openapi-fetch` client generated from the backend contract replaces axios.
  - Middleware handles the CSRF cookie, a single retry on 419, 401 → sign-in, and 402 → the dialog.
  - React Router 8 route modules; the `/app` loader redirects to `/login?next=…` without a session. `?next=` only accepts paths inside `/app`, which prevents open redirects.
  - react-hook-form + zod through a local resolver. `@hookform/resolvers` was left out because its optional peer chain conflicts with React Router's valibot.
- **New:**
  - Target-language settings, with usage against the limit.
  - A `has_password`-aware password form: Google-only accounts *set* a password.
  - Semantic, accessible markup (`output`, `fieldset`/`legend`, labelled fields with described errors).
- **Proof:**
  - removing the 419 retry fails its tests;
  - loosening `?next=` fails five tests, including the e2e one;
  - the e2e suite runs the whole sign-up → settings → 402 → sign-out → sign-in flow on the real stack.

### M1 · New — staff two-factor sign-in (`Domain/Identity/TwoFactor`, `/admin`)
- **Purpose.** The staff area sees every workspace. It requires TOTP set up **and** proven in the current session; the proof holds for 12 hours and the session id is regenerated when it is given.
- **Secret.** Encrypted with the app key. The QR code is drawn on our own server, so the secret never reaches a third-party QR service.
- **App codes.** Each code is accepted once. The last time step is stored under a row lock, so a replayed code or two concurrent uses of one code fail.
- **Recovery codes.** Eight codes, shown once and stored as SHA-256 hashes. Each works once, and any spacing or case is accepted when typing them.
- **Limits and errors.** Attempts are limited to 5 per minute. `EnsureStaff` answers `two_factor_setup_required` or `two_factor_required`.
- **`/admin` (SPA).** It walks staff through setup (QR, manual key, recovery codes) and the per-session check, then shows a first platform overview counted in the system context.
- **Proof.** Removing the replay check fails the "only once" test. Removing the per-session proof fails three tests.

### M1 · `TenantProvisioner` → `Domain/Identity/Actions/ProvisionWorkspace`
- **Kept:** one owned Free workspace per sign-up path; owner membership; the active workspace.
- **Changed / fixed:**
  - It is idempotent.
  - Slug races are retried inside savepoints (tested with a simulated concurrent insert).
  - Greek and other names are transliterated.
  - Empty or symbol-only names fall back to the email, then to `workspace`.
  - Plan, owner and slug are set with `forceFill`; they are not mass assignable (D21).

### M2 · `FileParser`, `FileUploadService::detect*`, `CommissionFeedFetcher::ensureUtf8/normalise`, `verifyArchiveIntegrity` → `Domain/Ingest` (preparer, readers)
- **Kept:**
  - Streaming every format, whatever its size.
  - The XML flattening rules: `a.b` for nesting, `a@attr` for attributes, `a[0]` for repeats, empty leaves left out.
  - The Excel `sep=` hint, and the legacy delimiter candidates (`,` `;` tab `|`).
  - Windows-1252 as the fallback for text that is not UTF-8.
  - The gzip and zip integrity checks.
- **Fixed:**
  - **D1** — gzip is inflated, by content rather than by file name. Truncation and CRC errors are refused (the legacy served a silently truncated gzip), concatenated members are read, and bombs are refused by size and ratio.
  - **D2** — every text feed is converted to UTF-8, streaming and chunk-safe (split UTF-16 surrogates and UTF-8 sequences are carried over). Invalid bytes become U+FFFD and are counted. Detection uses the BOM, the NUL pattern of BOM-less UTF-16, UTF-8 validity, then the XML declaration. The customer can override it with 15 encodings, including Windows-1250 and Windows-1253, which mbstring cannot convert: intl's UConverter is used instead.
  - **D3** — duplicate and blank headers become `Price (2)` and `Column 4` instead of failing.
  - **D4** — namespaced XML fields are kept with their prefix (`g:id`, `g:shipping.g:price`).
  - **D5** — XLSX is streamed by OpenSpout (a million rows in bounded memory) instead of being loaded whole.
  - **D23** — the XML item is the element that repeats most under a parent that does not repeat. `rss/channel/item`, `feed/entry` and `catalog/products/product` all work; the customer can name the path.
  - The CSV parser's non-standard backslash escape is off (RFC 4180): `"C:\"` stays `C:\`.
  - A malformed XML or JSON file stops the import with the line or problem named, instead of being half read: half a catalogue is how the legacy's disappear incidents started.
- **New:**
  - A content sniffer: an old `.xls`, a PDF, a web page (a login page behind a feed URL) and binary data are each refused with the reason.
  - JSON: a top-level list, a list inside an object (the pointer is found with a bounded scan), and NDJSON. Numbers keep their exact text (`19.90`, a 20-digit GTIN): `json_decode` would change golden values.
  - XXE and billion laughs: a document declaring entities is refused, and nothing is fetched (`LIBXML_NONET`, no DTD loading).
  - Mixed-content HTML in XML is kept as markup.
  - Caps: bytes, inflated bytes, inflate ratio, rows, columns, bytes per cell and nesting depth. Each refusal names its cap.
  - NUL bytes are removed and counted (PostgreSQL text cannot hold them).
- **Dropped:** the Competec-specific joiners (`Categories.joined3`, `attributes.flat`, `Dimensions.formatted`, `LongTextBullets.*`). Composing columns belongs to mapping (M3), for every feed.
- **Proof:** 22 mutants, one per guard, each made its test fail. The mutation run also found a test whose emoji did not actually straddle the chunk boundary; it was fixed.

### M2 · `AnalysisService` → `Domain/Ingest/Profile/ColumnProfiler`
- **Kept:** one pass over every row; per-column fill rate; the sample rows for the mapping review; SKU candidates by name (the legacy list, plus Google's `g:id`).
- **Changed:**
  - The SKU column is chosen on **evidence**: every row filled and every value unique wins over a better name. Otherwise it is offered at low confidence, with its gaps and repeats counted. Uniqueness is tracked up to a cap and never claimed beyond it.
  - Whitespace-only values count as empty.
- **New:**
  - The value kind of each column, from a sample: GTIN (check digit verified), integer, decimal, boolean, date, image URL, URL, HTML, code, text. `0012345` is a code, never a number.
  - A few examples and the longest length per column.
  - A column signature (the names in order): the same signature means an earlier mapping still fits (D6 is fixed with it in M3).

### M2 · `FeedDownloadService::downloadHttp`, `CommissionFeedFetcher::downloadHttp` → `Domain/Ingest/Fetch` (HTTP only; SFTP/FTP in M8)
- **Kept:**
  - 403 and 429 mean a cooldown, not a failure;
  - a `Content-Length` the body does not reach is a truncated download;
  - streaming to disk;
  - clear messages for 401 and 404.
- **Fixed:**
  - The Commission fetcher turned TLS verification **off**; it is always on.
  - Redirects were followed blindly by curl; now each hop is checked.
  - Nothing stopped a URL from reaching the metadata service or the host's own Redis.
- **New:**
  - The SSRF policy of ADR 0013: URL rules, public-only addresses including IPv6 wrappers, the connection pinned to the checked address (no DNS rebinding), hand-followed redirects that are re-checked, and a size cap.
  - `Retry-After`, capped at a day.
  - Conditional requests (ETag, Last-Modified).
  - `Accept-Encoding: gzip`: a gzip body is stored as sent and inflated with its integrity checked.
  - An arch rule: raw curl, sockets and DNS only in `Ingest/Fetch` (and AI providers).
- **Dropped:** URL-embedded credentials (refused); FTP and SFTP (M8, with encrypted credentials and D16).
- **Proof:** 12 mutants, one per guard, each caught.

### M2 · `Support/Uploads/ChunkedUpload`, the upload controller → `Domain/Ingest/Actions/Uploads`, `Http/Controllers/App/UploadController`
- **Kept:**
  - Fixed-size chunks (5 MiB), sent in any order and retried at will, assembled on `complete`.
  - The size cap is checked before the first byte is sent.
- **Fixed:**
  - The legacy built paths from a client-supplied upload id, and a crafted one deleted files outside
    storage. Every path is now made by the server: `uploads/{tenant}/{uuid}/{index}`. The route only
    matches a UUID and a number, the upload must belong to the feed in the URL (scoped bindings), and
    another workspace's upload answers 404.
  - A chunk must have exactly its expected length (the copy stops one byte past it). It is written to
    `.part` and renamed, so a half-written chunk never counts as received.
- **New:**
  - An optional SHA-256 of the whole file, checked after assembly.
  - Sessions expire after a day (410 afterwards); completing one closes it.
  - The client's file name is only displayed: base name, no control characters, at most 200 characters.
  - `ingest:prune`, daily at 03:30: expired upload sessions with their chunks, job work folders
    untouched for a day, and staged files older than a day. Stored feed files are never touched;
    `--dry-run` lists without deleting.
- **Proof:** the prune rules — 8 mutants, each caught.

### M2 · New — feeds and imports (`Domain/Feeds`, `Domain/Ingest/{ImportRunner, Jobs/ProcessImport, Actions/QueueImport}`, `/api/v1/feeds…`, `/api/v1/imports…`)
The legacy "supplier" becomes a **feed**. It has no legacy code to port: the legacy read files inside
the scan, so a file it could not read had no record of its own.
- **Tables** (all under RLS):
  - `feeds`: the source (URL or upload), status, and the reading options the customer chose.
  - `feed_sources`: the URL, encrypted (only its host is ever returned: feed URLs often carry an
    access token), with the ETag and Last-Modified of the last good read.
  - `imports`: one per file received, with how it was read (format, containers, encoding and why,
    delimiter, XML item), the profile, sample rows, SKU choice, warnings, or the failure reason.
    `sample_rows` is `json`, not `jsonb`: jsonb reorders keys, and columns must show in feed order.
  - `import_columns`: one row per column of the profile.
  - `upload_sessions`.
- **The run:** `queued → fetching → reading → profiled | unchanged | failed`.
  - **One unfinished import per feed**, enforced by a partial unique index; a second request gets
    409 `feed_busy`. An import unfinished for longer than its job may run (plus a margin) is
    expired, so a dead job never locks a feed, and the job's `failed()` marks only an unfinished
    import as `interrupted`, in its own workspace.
  - Files are stored once per workspace under their SHA-256 (`imports/{tenant}/{ab}/{sha}`). The
    same bytes read with the same options, or a 304, are `unchanged` and reuse the last profile.
  - ETag and Last-Modified are remembered only after a successful read, and sent only while the feed
    has a readable profile (a 304 must never leave a feed with nothing).
  - A file that could not be read is kept, so it can be **read again** with a corrected encoding,
    delimiter or item path. The choice is saved on the feed for the next import.
  - **Feed size per plan** (ADR 0014): Free 2 GB, Pro 10 GB, Ultra 50 GB, measured uncompressed,
    within the server's ceiling. Checked before an upload starts (402 with the plan that reads it),
    while downloading and while reading. Each feed keeps the files of its last three imports, plus
    its profile's.
- **Queue:** `ProcessImport` runs once, for up to three hours (the largest plan's feed); every
  connection's `retry_after` (11,100 s) exceeds the longest job timeout, and a test compares them (a
  shorter `retry_after` runs a job twice).
- **Limits and roles:** feeds count against the plan (paused ones too), checked under the workspace
  row lock (402 naming the plan that fits). Owners and admins manage feeds; members read and import.
  Fetching on demand is rate-limited (10 a minute per user).
- **API:** statuses, formats, value kinds, encoding reasons and SKU confidence are enums in the
  OpenAPI contract, so the frontend handles each of them.
  - A feed carries `profile_import_id`, the import whose profile is current; it stays while a later
    import fails. An import says whether it `can_reread` (its file is stored).
  - The feed list and a feed's last 20 imports load summary columns only: the list is polled while
    imports run, and samples or profiles never travel with it.
- **Proof:** 21 mutants over the pipeline and API; 20 caught. The survivor is equivalent and kept on
  purpose: the UUID route constraint repeats what the UUID model binding already does (404).

### M2 · Frontend `AddSupplier.tsx` → `frontend/src/features/feeds`, `routes/app/feeds.tsx`, `routes/app/feed.tsx`
- **Kept:** one dialog for a URL or a file, with progress while the file uploads.
- **Changed:**
  - The browser uploads in chunks: three at a time, in any order. A chunk is retried twice after a
    network or server error, never after a refusal. **The first failure stops every other chunk.**
    Without that, a failed upload kept sending (and retrying) in the background. A test proves it,
    and fails when the stop is removed.
  - A failed upload never makes a second feed: "Upload again" goes into the feed already created.
  - Over the plan's feed limit, the 402 hands over to the upgrade dialog.
- **New:**
  - The feeds list (source, size, last import's status) and the plan's feed usage.
  - The feed page:
    - how the file was read: format and containers, encoding and why, separator, XML item, SKU;
    - the column profile and sample products;
    - the status, polled while an import runs;
    - a failure in the customer's words, with the wait a rate-limited source asked for;
    - "read it differently", showing only the options that matter for the format;
    - fetch now (409 while busy), upload a new file, delete, and the import history.
- **Proof:** 34 Vitest tests. The Playwright demo of M2: an Excel upload and a Google Shopping URL
  each become a column profile, and the Free plan stops at one feed. End-to-end runs use the sync
  queue, may fetch from 127.0.0.1, and keep the cache in memory (rate limits are tested in Pest;
  the suite signs up many accounts from one address).

### M3 · `Observability/{EngineCallLogger,PricingService}`, `config/engine_pricing.php` → `Domain/AI/{Gateway/Recorder,Pricing}`, `config/ai.php`; new — the gateway chain and the credit ledger (`Domain/Credits`)
- **Kept:**
  - one log row per model call, with tokens, latency and status;
  - cost from the provider's own usage report and a per-model price list in config.
- **Fixed:**
  - **D13** (429s were never retried): `Retry` retries rate limits and outages on the same model, waiting the provider's Retry-After or a full-jitter backoff (base × 2^(attempt − 1), capped). A Retry-After beyond the cap goes back to the pipeline instead of holding a worker. 429s never trip the circuit breaker.
  - **D14** (daily spend was a cache read-modify-write): every charge is one row-locked, capped `UPDATE` of the work unit's reservation, written in the same database transaction as its `engine_calls` row; balances move only with conditional `UPDATE`s under CHECK constraints (ADR 0016).
  - Thinking tokens were not billed and cached input had no price: both are billed now, at the prices the provider charges.
  - Floats for money: prices are decimal strings, costs exact decimals (`brick/math`), the charge rounded up to a whole millicredit.
  - A model without a price was callable (at a zero cost): it is not callable now.
- **New:**
  - The chain of ADR 0004, built in one place (`AiGateway`); an arch test keeps every other class from using a link directly, or a provider:
    PolicyGate → ReservationGate → Router → CircuitBreaker → Retry → Recorder → ProviderDispatch.
  - **Policy:** the kill switch (`FEEDGENIX_AI_ENABLED`, off by default), the call's workspace must be the one in context, frozen or AI-paused workspaces and unverified owners are refused, and no reservation means no call.
  - **Reservations:** the call's worst case on its costliest possible model must fit before anything leaves (no half-done fallback), and again before every attempt.
  - **Answers:** the returned ids must be exactly the items' ids; missing, extra or renumbered ids are terminal (`misaligned`, billed and recorded, never guessed), and so is a cut-off answer.
  - **Routing:** per purpose; mapping and quality fall back to Claude, translation never switches models. Unpriced models and providers without credentials are skipped.
  - **Ledger:** wallets, reservations (expiring, swept every five minutes), append-only transactions, `credits:grant` (the beta AI budget, ADR 0016).
  - `FakeAiProvider` under the real chain for tests and e2e (`FEEDGENIX_AI_FAKE`, refused in production); the providers themselves arrive with step 2.
- **Dropped:** the daily USD cap kept in cache (the wallet balance is the hard limit).
- **Later:** plan model gating (`models`, `model_choice`) with the first user-chosen model (M4); value-schema validation of each answer with the providers (step 2).
- **Found on the way:** Redis returns cached numbers as strings, so a breaker that checked `is_int()` would never have opened on the servers (the array cache in tests returns ints). A test runs the breaker on a store that behaves like Redis.
- **Proof:** 106 new tests. Each guard was broken once and its tests failed: 20 of 21 mutants caught. The survivor is the explicit `tenant_id` filter in `ConsumeReservation`; row-level security hides another workspace's reservation without it (the attacker test still passes), which is the point of ADR 0002.

### M3 · `ClaudeApiClient`, `GeminiApiClient`, `StructuredJson`, `Support/Tenancy/AiEgressGuard`, `Support/Http/Tls` → `Domain/AI/Providers` (ADR 0017)
- **Kept:** one call per batch of items, JSON in and out; a per-host timeout.
- **Fixed:**
  - **D11** (the Gemini key travelled in the URL and leaked through exception messages): the key is in `x-goog-api-key`; no failure message repeats a request or a response body, and SDK exceptions (which hold the request) are never chained.
  - Claude's `stop_reason` was ignored, so a cut-off or refused answer read as complete: `max_tokens` is truncated, `refusal` and a full context window are terminal.
  - Gemini's SAFETY, RECITATION and blocked prompts came back as empty answers: they are typed, billed failures now.
  - Thinking tokens were never counted: Gemini's `thoughtsTokenCount` and Claude's `output_tokens_details.thinking_tokens` are recorded, and billed exactly once.
  - `StructuredJson` returned `[]` on any failure: invalid JSON, a missing `items` list or an item without a value is `malformed`, a repeated id is `misaligned`.
  - The egress guard was a denylist of frozen workspaces (fail open): it is an allowlist of callers now (fail closed), on every outbound request.
  - `Tls` switched certificate checks off locally: verification is always on, and an arch test bans switching it off.
- **New:**
  - Claude through the official SDK (`anthropic-ai/sdk`) over Laravel's HTTP client, so the SDK's traffic is guarded and faked like the rest; `claude-opus-5-5` as the reasoning fallback.
  - Structured outputs on both hosts, with the item ids as an enum; the gateway checks every answer against its value schema (`ValueSchema`) whatever the host promised.
  - Claude refusal fallbacks, offered only when priced and billed per hop at each model's rate (`BilledUsage`).
- **Dropped:** the free-tier Gemini path; the per-call key lookup from the database (keys are server configuration).
- **Later:** the first call on each real key confirms the live request shapes (integrations phase).
- **Proof:** 71 new tests (Gemini 23, Claude 18, providers under the gateway 15, value schema 13, TLS ban 2). Each guard was broken once: 16 of 16 mutants caught.

### M3 · New — the canonical schema (`config/canonical_schema.php`, `Domain/Catalog/Schema`, ADR 0003)
- **Replaces** the legacy `TARGET_FIELDS`: Channable's French column names times four fixed languages (`titre_fr` … `short_description_it`).
- **Fields** with English keys, in six classes: golden (exactly the ten of rule 1), pass-through, translatable, composable, optimize-only and derived. Each has a value kind for validation, a label and a one-line description for the reviewer and the prompt, a default translation mode for localized fields (sizes are kept), and column-name hints in several languages.
- **`field@locale`** (`FieldRef`): localized fields always name a BCP-47 locale from the registry, matched case-insensitively (`title@de-ch` → `title@de-CH`); golden, pass-through and derived fields never take one.
- **Fail loud:** an unknown class or kind, a localized field without a mode, a shared field with one, or a key that is not lower snake case refuses to load.
- **Later:** a workspace's own custom fields (`target_fields`, with the field-selection UI in M4).
- **Proof:** 19 tests.

### M3 · `MappingService` (generateMapping, composeFields, sanitizeCompositions, enforceStrictComposition, buildFieldReports, isProtectedColumn, refineMapping, validateMapping), `FieldValidator`, `SmartScanner::composeFromRecipe` → `Domain/Mapping` (ADR 0018)
- **Kept:**
  - value-aware validation per field (URLs, image paths, barcodes, pure numbers, sizes in a colour, taxonomy ids in a category), with the 30% rule between a warning and a refusal;
  - "empty rather than wrong": a failed check leaves the field empty with the reason;
  - composition of scattered columns into one field ("Label: value" lines), with the gates: confidence ≥ 0.5, something composed on the sample rows, no failed validation;
  - per-field reports for the reviewer (status, sample values, reason, confidence).
- **Fixed:**
  - **D6** (a mapping reused by file id across uploads with other columns): mappings are keyed by feed and column signature.
  - **D7** (refine and save skipped validation): every change, a person's included, goes through one pipeline.
  - **D8** (letters `[a-zA-ZÀ-ÿ]`, words by `str_word_count`): Unicode letters and words, plus a check that a localized field is written in its locale's script.
  - `isProtectedColumn` matched `ean`, which never matched `product_ean` or `EAN_Code` (`_` is a word character): column names are split into words (also camelCase and acronyms) before matching.
  - The prompts sent the first three raw rows, prices and barcodes included: protected columns' values are withheld, by name and by value kind.
  - Compositions could take protected columns when the post-parse guard missed them: the pipeline drops them, and numeric measurements stay golden.
  - Two columns claiming one field: the legacy kept whichever came last; close calls now stay empty for the reviewer.
- **Changed:** one AI call per batch of 40 columns, with an id per column (the gateway's misalignment check applies), instead of one free-form JSON object per feed; Swiss/DeinDeal prompts and the four fixed languages are gone.
- **Dropped:** the forced `categorie_*` translation for every language; the "translation fields" toggles (they come back as the field selection in M4).
- **Proof:** 129 tests (columns and languages 47, validator 34, drafts 4, suggestions 22 including a 12-seed property test over random feeds, edits and approval 14, API 8) and 15 of 15 guard mutants caught.

### M3 · Legacy Mapping Studio (Filament) → `frontend/src/routes/app/mapping.tsx`, `features/mapping`
- **Kept:** one row per field with its source column, status, reason and sample values; AI suggestion on request; approval.
- **Changed:**
  - Fields are grouped by what happens to them (copied exactly, copied, translated, composed), and the uncommon ones are folded away.
  - A protected column is never offered to a text field.
  - Corrections for the AI are plain words; the most a suggestion can cost is shown in credits before it runs (never a provider cost).
  - The feed's language is said by a person when the texts do not give it away (`PATCH …/mapping`); regional variants (`de-CH`) win over a detected `de`.
  - Edits carry the version they were made on; a stale one reloads with an explanation.
- **New:** the feed page links to the mapping, and the pipeline's mapping stage shows draft or approved.
- **Proof:** 13 Vitest tests for the page, the stepper's states, and a Playwright step from a fresh Excel upload to an approved mapping; 4 Pest tests for the feed language and the feed's mapping status.

### M3 · New — mapping evals (`resources/evals/mapping`, `Domain/Mapping/Evals`, `mapping:eval`)
- **Cases:** eight synthetic feeds with known answers (German, English, Google Merchant, French, Greek, abbreviated and camelCase, one column per language, meaningless names). No real data.
- **Score:** precision and recall per case; a wrong golden field and a protected column in a text field are counted on their own, and the bar for both is zero.
- **Baseline** (`baseline.json`, the free draft without AI): precision 100%, recall 82.9%. A test fails below it; raise it when the mapper improves, never lower it to let a change pass.
- **Bounds:** a perfect AI scores 100% on every case, in every script (the pipeline accepts every right answer; D8); a hostile AI that proposes every column into every text and golden field never gets a protected column into text, nor a text column into a golden field.
- **`mapping:eval --ai --workspace=… [--record]`:** asks the configured model too, through the gateway, paid from that workspace's credits; `--record` keeps the answers so a prompt or model change can be compared (ADR 0004).
- **Proof:** 6 tests.

### M4 · `SmartScanner` (scan, scanChunk, finalizeChunkedScan, invalidateStaleTranslations, composeFromRecipe, disappear guard, isEffectivelyEmpty) → `Domain/Catalog/CatalogSyncer`, `products`, `product_texts`, `pipeline_runs` (ADR 0019)
- **Kept:** compositions from recipes; stale translations when their source changes; the disappear guard (30% of a catalogue over 50); manual translations never overwritten.
- **Fixed:**
  - **D9** (identical texts in several languages unset everywhere but one, short ones lost): language columns are kept as mapped.
  - **D10** (the parallel path's disappear step had no guard): one path; a failed sync never reaches the removal step.
  - The merge kept every old value for ever: a blanked cell removes the value, and its translations become stale.
  - `empty()` dropped a literal "0"; placeholders like "n/a" were stored as text and translated: golden values are kept exactly, and texts that are only placeholders or empty markup are not texts.
  - Two `isEffectivelyEmpty` that disagreed (placeholders vs HTML): one, `EmptyValue`, knowing both.
  - N+1 lookups per row and a chunk path that re-parsed the whole file per chunk: chunks of 500 with one lookup and bulk upserts each.
  - Chunk checkpoints that resumed stale runs: no checkpoints; the database rows are the state.
- **Dropped:** the supplier-specific colour guard and enrichment, category brand stripping and taxonomy cleanup (they come back as general normalisers in M12, if at all); the identical-text language analysis.
- **Proof:** 12 tests; 7 of 7 guard mutants caught.

### M4 · `TranslationRouter`, `TranslationService`, `Quality/{QualityChecker,QualityResult,AlignmentMarker}`, `config/translation_field_rules.php` → `Domain/Translation`, `Domain/Quality`, `config/translation_rules.php` (ADR 0020)
- **Kept:** the translation memory; batch sizes by field length; the field rules (keep numbers, units, brands and HTML; category levels; Swiss "ss"); a breaker (now the gateway's); numeric, length and brand checks.
- **Fixed:**
  - **D12** (answers matched by the number the model wrote): an id per item through the gateway; a misaligned batch splits in two down to depth 6, and an item that never aligns fails visibly.
  - **D15** (checkpoint resume never resumed): no checkpoints; units in the database are the state, with leases, and a killed worker costs a lease timeout.
  - The memory stored every answer before checking it and served bad ones for ever: only checked translations are kept, and the field is part of the key.
  - Numbers were matched as substrings ("100" in "1000") and only the source's were looked for, so an invented one passed; HTML, scripts and echoes were never checked; hard failures were still saved as translations: the same whole-token numbers on both sides, the same tags in order, the target's script, category levels, echoes flagged, and hard failures stored as `qa_failed`, never published.
  - Swiss "ss" was prompt-only: enforced in code.
  - One source language for the whole catalogue: chosen per product.
  - The daily spend counter in cache and silent empty results at the provider cap: reservations per unit, and holds that resume.
  - Busy locks dropped chunks until a later re-dispatch: the dispatcher claims what the plan's slots allow, the rest waits in the database.
- **Changed:** a per-feed plan (translate, keep or skip per field; the workspace's languages) with the work and an estimate in credits before it starts; the allowance counted per product and period.
- **Dropped:** the marketplace-specific prompt, glossary and few-shot examples (glossaries come back per workspace in M9); the Gemini context cache; the async batch API.
- **Proof:** 34 tests (plan 6, quality 15, runs 13: renumbered ids, never-aligned items, 429 storms, no credits, allowance on Free and Pro, a killed worker, a source changed in flight, a person's translation, golden fields through en/el/ja/ar/de-CH, Swiss "ss", prompt injection kept as data); 10 of 10 engine guard mutants caught.

### M4 · New — the translation pages and the review (`routes/app/{translation,products}.tsx`, `features/{translation,review}`, `TextReview`, `Actions/ReviewTranslation`)
- **What:** the plan page (languages, each field's mode, the work with the memory, the estimate in credits, Start, the run followed in texts, why work waits); the review of the texts one language at a time (to review, missing, yours; search by SKU or title); a person's word on a text: write it, approve the AI's as it is, or hand it back to the AI.
- **Rules:** every write is fenced by the source the person saw (409 when it changed in the feed); only translations are touched, never the feed's own text, a golden field, a language outside the workspace or a product gone from the feed; a person's translation is never replaced by the AI and goes into the memory for the same text; a text handed back is forgotten by the memory too, so the AI really translates it again.
- **With it:** the plan and translation stages are live on the feed page; a file that arrived while its feed was busy is read once the feed is free (ADR 0019 said so; nothing did it); the products can be read again after a failed read.
- **Proof:** 8 review tests, 3 for waiting files and reads, the page tests (translation 10, review 8, stages, feed card), the e2e flow from an upload to a translation the owner writes; 27 of 27 guard mutants caught.

### M5 · `ExportService` (export, writeCsvChunked, escapeField, validateExport, shrink guard, pruneOldVersions, rollbackExport, resolveFieldValue), `BuildSupplierFeed::writeGzipSidecar`, `DescriptionFormatter`, `ShortDescriptionMaker` → `Domain/Export/{ExportBuilder,FileChecks,ColumnPlan,Transforms,Dialects}`, `Domain/Catalog/Formatters`, `config/export.php` (ADR 0022)
- **Kept:** write to a temporary place and publish only a checked file; the shrink guard; a verified gzip; a few old builds to go back to; published texts only when they passed the checks; never another language instead; no empty columns; the description and short-description formatters.
- **Fixed:**
  - Every `"` became `'` in every value (23" became 23', a golden code with a quote changed): RFC 4180 quoting in CSV writes every value unchanged; in TSV a golden value that cannot be written as it is takes its row to the report.
  - The check parsed with other escaping rules than the writer, and let 1% of the rows go missing: each dialect reads its own files back, and the rows read must be exactly the rows written.
  - The gzip was a sidecar: when it failed the old `.gz` stayed next to a new file: the plain file and its gzip are one build, published together or not at all.
  - Publishing and rollback copied files in place (a channel could read half a file): immutable keys and a pointer moved in one transaction.
  - A request for a missing file started a build: requests never build (ADR 0007; serving is the next step).
  - The short description knew only Latin sentence ends and could not cut a text without spaces: CJK, Arabic and Devanagari sentence ends, and a cut at the limit.
  - The formatter ran on every description of every feed: a column transform a preset asks for.
- **Dropped:** supplier-specific columns and normalisers (colour, composition, size, gender, the category dictionary, Title Case, four category levels, `_intel` headers, the canonical Competec description), the new-products feed, the global cache kill switch.
- **Proof:** 28 tests (dialects 3, transforms and formatters 6, file checks 8, builds 11); 17 of 17 guard mutants caught.

### M5 · `ExportDownloadController` (publicFeed, publicFeedGz, the kill switch), `BuildSupplierFeed` (shouldRebuild, the lock, the last-known-good copy) → `Http/Controllers/Public/FeedFileController`, `Domain/Export/{Jobs/BuildExportJob,Actions/*}`, `ops:reconcile` (ADR 0023)
- **Kept:** prebuilt files only; gzip for clients that take it and a `.gz` URL; one build of a feed at a time; a rebuild when the translations are done.
- **Fixed:**
  - A request for a missing file queued a build (anyone with the URL could start work): requests never build; 503 with Retry-After until the first build.
  - Tokens were stored and compared in plain text: hashed for lookup, encrypted for display, rotatable with a 72-hour grace.
  - A per-supplier cache lock and a copied last-known-good file kept a half-written feed off the URL only in the good case: immutable builds and a pointer (ADR 0022); a unique job per profile; killed builds failed by the reconciler.
  - Rebuilds waited for translations or a daily timer, so new prices waited too: every change to the catalogue (a new file, a finished run, a person's edit) builds the exports once nothing reads or translates the feed.
  - No ETag (every poll downloaded the whole feed): the file's SHA-256, and 304.
  - A TSV was served as `text/csv`; feed answers carried cookies and could be indexed: the dialect's type, no session, `noindex, nofollow`, rate limited per token.
- **Dropped:** the global cache kill switch (pausing an export, or deleting it, is per profile), the admin download routes (the URLs are the download), the preview from the database (the published file is the preview).
- **Proof:** 19 tests (URLs 8, API and reconciler 11); 19 of 19 guard mutants caught; the ops rehearsal checks that `/f/` reaches Laravel through nginx.

### M5 · New — the publish page (`routes/app/publish.tsx`, `features/publish`), stage 6
- **What:** each export's feed URLs to copy (and the `.gz` form), the build they serve (products, size), the products a channel would refuse for a missing column, rows a format could not carry, a build the shrink guard held back with "Publish it anyway", failures, earlier builds to serve again, new URLs after asking (the old ones work 72 hours), pause and resume, delete after asking; a new export offers only the formats and files its channel takes. The feed page links to it and stage 6 is live: next once products are in the catalogue (publishing does not wait for the translation), done when a feed URL is live.
- **Proof:** 8 page tests and the stage tests; the e2e flow now creates an export and pulls its URL from Laravel; 7 of 7 guard mutants caught.

### M6 · `PlanLimitsApplier`, `EnsureFeedActive`, `TenantsSyncPlans` → `Domain/Billing/PlanLimitsApplier`, `Http/Middleware/EnsureFeedActive`, `plans:apply`, `Translation/Listeners/HoldPausedWork` (ADR 0024)
- **Kept:** nothing deleted; the least recently changed feeds pause and the most recent resume; a paused feed keeps serving its last export; viewing stays open; the hourly pass.
- **Fixed:**
  - Only feeds paused (every language kept translating after a downgrade): languages over the limit pause too, the last added first, and nothing new is translated into them.
  - Queued AI work of a paused feed ran on: it is held, and handed out again when the feed or language resumes.
  - The pause itself updated `updated_at`, so the next downgrade paused the wrong feeds: pauses never touch it.
  - The hourly sync applied every workspace every hour: only those whose plan in force differs from the one applied.
  - Deleting a feed or removing languages left the others paused until the next hour: they resume at once.
- **Changed:** the 402 says what still works ("Its feed URLs keep working") and which plan fits.
- **Not yet:** `EnsurePlanFeature` comes with the first feature-gated route (M9).
- **Proof:** 8 tests; 15 of 15 guard mutants caught; the feed page, plan and settings show what is paused.

### M6 · New — notices (`Domain/Notices`, `/app/notices`, ADR 0025); replaces the legacy `PipelineAlert::notifyAll`
- **What:** a notice per workspace, once per key, in the app for everyone and by email to the verified owner and admins when it needs a decision: 80% of the period's products, products waiting for the next period, translations waiting for credits, what a plan paused or resumed (by name), an export build held back. The plan page says before a start what it counts against the allowance (`Admission::preview`, the same count the start makes).
- **Fixed against the legacy alerts:** they went to every operator for every event, repeated on every run, and the shrink alert was the only one a customer got: one notice per event and key, to the people who decide, with the way to act on it and the dates.
- **Proof:** 10 notice tests, the page, badge and allowance tests; 11 of 11 guard mutants caught.

### M6 · New — the staff area (`Domain/Ops`, `/admin/workspaces`, `/admin/audit`, ADR 0026); replaces the legacy operator pages
- **What:** every workspace with its plan in force, owner, feeds (and how many are paused), languages, products this period, credits available and AI credits over 30 days, searchable by name, slug, owner email or id; one workspace in full (members, feeds with their state, work that waits by reason, AI calls and tokens by purpose, the ledger, notices, what staff did there); the staff trail. The two-factor gate now covers every staff page.
- **Fixed against the legacy operator pages:** they showed provider cost and read every tenant's rows unscoped, and nothing recorded who looked: credits and tokens only, each workspace read in its own context, and one append-only line per staff request.
- **Staff actions:** credits granted (worth an amount in euros, with why) and the plan set by hand until Stripe, each confirmed and described in the trail in credits. One code path with the commands: `Millicredits::parseGrantEuros` and `Billing\Actions\SetPlan` serve `credits:grant`, `tenants:set-plan` and the staff area alike.
- **Work across workspaces** (`/admin/work`): runs that failed in the last 7 days, wait, or show no change for 30 minutes; batches held by reason; the queue (`Queue::size()`, so Redis on the server); the last ten failed jobs by name with the first line of the error only (the trace stays on the server); and whether the reconciler ran in the last five minutes (a heartbeat it writes through the cache at the end of every pass).
- **Not yet:** a read-only "view as".
- **Proof:** 34 API tests, 11 page tests; 17 of 17 guard mutants caught (no trail line, an unescaped search, the detail read in the system context, the staff gate removed, a non-numeric id bound by the router; a grant or plan change not described, no note required, no upper limit, a past date, limits not applied, an unknown plan answered with a 500; a moving run shown as stuck, old failures shown, no heartbeat, a silent reconciler shown as running, the whole trace shown).

### M6 · Fix — partial admission and waiting work counted before it runs (ADR 0027)
- **Defect found:** a run over the allowance held every unit and counted nothing; in the next period the reconciler released the units uncounted (Free, allowance 3: five products translated, none counted, every month). A workspace with more new products than its allowance translated nothing in any period.
- **Now:** a start counts what fits and holds only the rest, in units of their own; `Translation\Actions\AdmitWaitingWork` counts waiting work through the same admission once there is room (a new period, an upgrade, a raised limit), splitting a unit that only partly fits.
- **Proof:** `tests/Feature/Translation/AllowancePeriodTest.php` (4 tests: across one and two periods, nothing in the same period, at once after an upgrade) fails on the old code (5 translated, 0 counted); 6 of 6 guard mutants caught.

### M7 · New — the overage fee (`Metering\Admission`, `Credits\Actions\ChargeFee`, ADR 0028)
- **What:** on a paid plan with overage on, the products over the allowance are charged 10 credits each in the same transaction that counts them (the wallet row locked, only available credits, never twice); what the credits do not pay for waits as `overage_credits` and is admitted, and charged, once credits arrive. The ledger has a new kind, `overage`.
- **Proof:** `tests/Feature/Credits/OverageFeeTest.php` (7 tests); 8 of 8 guard mutants caught (fee from held credits, the conditional debit removed, every overage product let in, no fee charged, fee-held work not re-admitted, retried blindly by the reconciler, credits never making room, waiting for the allowance instead of credits).

### M8 · `FeedImportController::importUrl`, `FeedDownloadService::downloadHttp` (its login) → `Domain/Ingest/Fetch/Credentials`, `Domain/Feeds/Actions/ChangeFeedSource`, `features/feeds/{LoginFields,EditSourceDialog}` (ADR 0030)
- **Kept:** a username and password on a URL feed.
- **Fixed:**
  - D16: saving a feed without the login wiped it; now only `auth: null` removes it, and an empty form is refused.
  - The password sat in a plain column and in the queue payload; now it is encrypted, jobs carry ids, and no response or log shows it.
  - A login could go over plain `http`, and could follow the address to any server; now https only, and one origin only, on redirects and on a changed address alike.
- **New:** a request header as a login (`X-Api-Key`); the page says whether a login is missing or refused, and offers the source settings.
- **Proof:** `tests/Feature/Ingest/SourceLoginTest.php`, the logins in `tests/Unit/Ingest/FetchTest.php`, the source settings on the feed page; 30 of 30 guard mutants caught (2 of them only after new tests: a final line break in a username, and a missing `#[SensitiveParameter]` hidden by `zend.exception_ignore_args`).

### M8 · `FeedDownloadService::downloadFtp` → `Domain/Ingest/Fetch/{SourceFetcher,FileFetcher,FileServer,CurlFtpServer,AddressPin}` (ADR 0031)
- **Kept:** a feed fetched from an FTP server, with a login or anonymously, in passive mode.
- **Fixed:**
  - No address check: `ftp_connect` reached whatever the host named; now the same SSRF rules as the web, pinned to a checked public address.
  - The `PASV` reply was trusted, so a server could aim the data connection at an internal address; now it is ignored.
  - No TLS at all; `ftps://` now demands it before the login, with the certificate verified.
  - No size cap and no integrity check on FTP: a short transfer looked like a feed; now the plan's cap applies and a body short of the announced size is a cut-off download.
- **New:** an unchanged file (same size and time) is not downloaded again; `not_found` as a failure of its own.
- **Dropped:** `ftp_*`; curl does the transfer, as on the web.
- **Proof:** `tests/Unit/Ingest/{FileFetchTest,FtpServerTest}.php` (the real curl client against a fixture FTP server on `127.0.0.1`), the addresses in `FetchTest.php`, FTP feeds in `SourceLoginTest.php`; 24 of 25 guard mutants caught. The survivor is equivalent: curl's own `CURLOPT_MAXFILESIZE_LARGE` also stops a download past the cap, before the write callback does.

### M8 · `FeedDownloadService::downloadSftp` → `Domain/Ingest/Fetch/{SftpServer,SftpSession,SftpConnector,PhpseclibSftpConnector,PhpseclibSftpSession}` (ADR 0032)
- **Kept:** a feed fetched over SFTP with a username and password, through phpseclib.
- **Fixed:**
  - The server's key was never checked, so anyone in between could collect the password; now it is proven and compared before anything is sent, trusted on first use or pinned up front, and a changed key stops the fetch.
  - `$sftp->get($path)` held the whole file in memory; now it streams to disk, up to the plan's limit.
  - No address check, and the host name resolved by the library; now a socket opened to the address that was checked.
  - The library's messages reached the customer; now they stop at the seam.
- **New:** a private key as a login, read when it is saved; the server's fingerprint on the feed page; a path relative to the home with `/~/`.
- **Proof:** `tests/Unit/Ingest/SftpServerTest.php` (the order of things against a scripted session; the real client against a real OpenSSH server on the CI runner), SFTP in `tests/Feature/Ingest/SourceLoginTest.php`, the forms; 32 of 33 guard mutants caught. The survivor is equivalent: a fetch that succeeded on a pinned key can only report that same key.
