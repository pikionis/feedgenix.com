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
_None yet — cards are added as each milestone starts._
