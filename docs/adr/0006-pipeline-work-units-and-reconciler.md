# ADR 0006 — Pipeline, work units, slots and the reconciler

- Status: accepted
- Date: 2026-09-28

## Context
The legacy pipeline relied on four overlapping "healer" crons to unstick runs, resumed checkpoints
that never actually resumed, and counted progress in several places that disagreed. Work stalled in
states nobody could explain ("covered but pending").

## Decision
- **Run states:** `queued → fetch → analyse → map [awaiting_mapping] → sync → plan
  [awaiting_decision] → translate → export → published | unchanged | failed`. One unfinished run per
  feed (partial unique index on `pipeline_runs`).
- **PostgreSQL is the authority; the queue is only transport.** AI work is split into
  `ai_work_units` (scope: field, source/target language, field class, style hash, source hashes)
  with leases (`lease_token`, `lease_until`) and a reservation.
- **`WorkDispatcher::pump()`** goes tenant by tenant (plan priority first, then least recently
  served) and claims up to `slots − leased` units with `FOR UPDATE SKIP LOCKED`. It runs when work is
  created or finishes, and every minute.
- **Idempotency everywhere:** stages compare-and-set on (stage, attempt); a lease token fences off
  zombie workers; writes are fenced by source hash (ADR 0003); reservations are unique per (unit,
  attempt).
- **Misalignment splits, never guesses:** a unit whose returned ids don't match exactly is replaced
  by two halves, down to depth 6; then the items fail visibly.
- **Failures are typed:** `AiRetryable` → backoff; `AiDenied` → held, resumes on its own when the
  cause clears (top-up, new period, upgrade); `AiTerminal` → item failed. After 5 attempts: failed,
  staff notified.
- **One reconciler (`ops:reconcile`, every minute, one server)** replaces all healers. It never
  fetches feeds and never calls AI. Each pass requeues expired leases, re-enqueues stalled stages,
  advances runs whose units are all finished, releases cleared holds, expires reservations, enqueues
  an export when `data_version` is ahead of the published artifact, and pumps the dispatcher.
- **Queue connections:** a short one (600 s) and a long one (7,800 s); `retry_after` always exceeds
  the job timeout. Queue names: `interactive`, `pipeline`, `ai-priority` (Pro and up), `ai`,
  `export`, `default`.
- **One catalogue finalizer** for every sync mode, with the disappear guard: skip it for incomplete
  imports and when more than 30% of a catalogue larger than 50 products would disappear.

## Consequences
- A killed worker costs a lease timeout, never a double count or a lost result.
- "Why is this stuck?" always has one answer: the unit's status and hold reason.
