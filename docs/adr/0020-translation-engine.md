# ADR 0020 — The translation engine: work units, fenced writes, checked memory

- Status: accepted
- Date: 2026-10-23
- Refines: ADR 0005 (metering), ADR 0006 (pipeline)

## Context
The legacy translator matched answers by the number the model wrote (D12), stored every answer in
its memory before checking it, kept a daily spend counter in cache, resumed checkpoints that it
never wrote (D15), counted progress in several places and dropped chunks when its locks were busy.

## Decision
- **A translation plan per feed** (`translation_plans`): per text field translate, keep or skip, and
  the target languages, which are the workspace's own. The source is chosen per product (the mapped
  feed language first).
- **Starting the plan** writes "keep" fields and the memory's repeats at once at no cost, and cuts
  the rest into **work units** (`ai_work_units`): one field, one source and one target language, at
  most 40 short or 10 long texts and 24 KB, each item carrying the source hash it was planned from.
- **Allowance:** the run's products are counted once per period (`usage_products`). Over the
  allowance, Free waits for the next period; a paid plan with overage on continues and the overage
  products are flagged (their fee is charged with the wallet, M7).
- **The dispatcher** claims pending units per workspace up to its plan's worker slots with
  `FOR UPDATE SKIP LOCKED` and a 15-minute lease token; a worker without the current token does
  nothing. Times are the application's clock, never the database's.
- **A unit run:** read the sources (items whose source changed since planning are skipped), reserve
  the call's worst case, call the gateway (`translate@1`, an id per item), check every answer, write
  it **fenced** — only while the source still has the planned hash, never over a translation a
  person wrote or a language the feed provides — and settle.
  - Misaligned, cut-off or refused answers split the unit in two, down to depth 6; then the item
    fails visibly. Retryable failures come back later (Retry-After or 30 s × attempt; 5 attempts).
    No credits, AI off or paused: the unit is **held** and resumes by itself.
  - Locale rules the model may miss are applied in code (Swiss German: "ss", never "ß").
  - **Quality checks** decide: a hard failure (numbers, HTML, script, category levels, or two
    failures) is stored as `qa_failed` and never published; one soft failure is published and
    flagged. Only checked translations enter the memory (keyed by field too).
- **The reconciler** (`ops:reconcile`, every minute, one server) requeues expired leases, retries
  holds for credits or AI after five minutes, releases allowance holds in a new period, advances
  runs, and pumps. It never calls AI.
- **Progress is counted in one place** (`RunProgress`, from the units): a run is done when no unit
  is pending or leased, held when the rest wait.

## Consequences
- A killed worker costs a lease timeout, never a double count or a lost result.
- An answer can only land on the product it was asked for, from the source it was asked from.
