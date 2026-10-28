# ADR 0024 — What is over a plan's limits pauses, never disappears

- Status: accepted
- Date: 2026-10-28
- Refines: ADR 0005 (metering), ADR 0007 (a paused feed keeps serving)

## Context
A downgrade, or a paid plan that lapses on its own date, leaves a workspace with more feeds and
languages than its plan includes. The legacy PlanLimitsApplier paused feeds only (every language
kept translating), let AI work already queued run on, and ran from a staff command and an hourly
sync.

## Decision
- **Nothing is deleted.** Feeds over the limit pause, the least recently changed first, and resume
  the most recently changed first; languages over the limit pause, the last added first, and
  resume in the order they were added. A workspace's own choice is kept whenever it fits. A pause
  is not a change: it never touches `updated_at`, so it never reorders the next pause.
- **A paused feed** (`feeds.plan_paused_at`) takes no new file, fetch, re-read, AI suggestion,
  catalogue read or translation (402 `feed_paused`, with the plan that fits), and is never
  translated by itself. Looking, the mapping, the texts and its exports stay open: its feed URLs
  keep serving.
- **A paused language** (`tenant_languages.plan_paused_at`) keeps its place in the plans and its
  texts, and gets nothing new translated.
- **Waiting AI work follows:** the pending units of a paused feed or language are held
  (`feed_paused`, `language_paused`) and handed out again once nothing keeps them paused. Units a
  worker holds finish; their credits are reserved.
- **When:** on every plan change (`tenants:set-plan` now, the billing webhooks in M6), when a feed
  or languages are removed (room for what was paused), and every hour for every workspace whose
  plan in force differs from `tenants.applied_plan` (a plan can lapse by date alone).
- Billing announces the pauses (`PlanPausesChanged`); the translation holds and releases its work.

## Consequences
- A downgrade never breaks a live shop: the URLs keep serving, the extras wait.
- Paused counts as used: pausing never frees a slot for a new feed or language.
