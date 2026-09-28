# ADR 0001 — Repository layout and stack

- Status: accepted
- Date: 2026-09-28

## Context
FeedGenix replaces the evolution of the legacy Feed Intelligence app (Laravel 11 + Filament + a
separate React SPA, MySQL, built around one customer's DeinDeal/Channable/Galaxus feeds). The owner
chose to start from scratch with no legacy dependencies, PostgreSQL + pgvector, and the admin inside
the same React app. One developer works on it with Claude Code, so the layout must keep the SPA and
the API easy to change together and deploy atomically.

## Decision
- **One repository, one artifact.** Laravel 13 at the root; the SPA in `frontend/`. The SPA and the
  API are served from the same origin (nginx in production, the Vite proxy locally), so Sanctum's
  cookie session needs no CORS.
- **Backend modules** under `app/Domain/<Module>` (Tenancy, Identity, Billing, Metering, Credits,
  Feeds, Ingest, Mapping, Catalog, Translation, Quality, Export, Agent, AI, Ops). Modules talk through
  Actions, events and five interfaces (`AiClient`, `VectorIndex`, `ArtifactServer`, `RowReader`,
  `Fetcher`). Controllers are thin and final, in `app/Http/Controllers/{App,Admin,Auth,Public}`.
- **PHP 8.3 is the syntax floor** (local machine); servers run 8.4. Larastan runs with
  `phpVersion: 80300` and Rector with the PHP 8.3 set.
- **Frontend:** React 19 + React Router 8 in framework mode with `ssr: false`. `/` (and later
  `/pricing`, `/faq`) is pre-rendered for SEO; every other path hydrates from
  `build/client/__spa-fallback.html`. Vite 8 (native tsconfig paths), Tailwind 4, shadcn/ui
  conventions, TanStack Query for server state, Zustand for UI state, react-hook-form + zod.
- **TypeScript 5.9, not 7.** TypeScript 7 (the native compiler) is current, but `openapi-typescript`
  still requires the TypeScript 5 API. Revisit when the generator supports 7.
- **API contract:** Scramble produces the OpenAPI document (`frontend/openapi.json`, servers pinned
  to the relative `/api`), `openapi-typescript` generates the types, `openapi-fetch` is the client.
  CI fails if either file drifts.
- **Fonts are self-hosted** (`@fontsource`), never loaded from Google (GDPR).

## Consequences
- A deploy ships backend and frontend together; there is no version skew between them.
- The admin is a lazy route tree in the same SPA behind the staff check (no Filament, no Livewire).
- New major versions of framework-mode tooling must be checked against the pre-render output
  (`index.html` + `__spa-fallback.html`), which nginx relies on.
