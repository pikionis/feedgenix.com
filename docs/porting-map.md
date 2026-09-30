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
| D16 | re-saving a URL import without credentials wiped the stored credentials | M8 |
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
