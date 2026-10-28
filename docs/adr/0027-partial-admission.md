# ADR 0027 — What fits the allowance runs; what waits is counted before it runs

- Status: accepted
- Date: 2026-10-28
- Refines: ADR 0005 (metering), ADR 0020 (translation engine)

## Context
A run over the plan's product allowance (Free, or a paid plan with overage off) was all or
nothing: every unit of the run was held with `allowance` and no product was counted. Two defects
followed, both found by a test that crosses into the next period:

- **A metering leak.** When the next period began, the reconciler set the held units back to
  pending, and they ran without being counted: on Free with an allowance of 3, five products were
  translated and none counted, and the same would repeat every month.
- **Nothing, ever.** A workspace with more new products than its allowance had every run held in
  full, so it translated nothing in any period, while ADR 0005 meant only the products over the
  allowance to wait.

## Decision
- **Partial admission.** A start counts the products that fit (the first new ones in the order the
  work was planned, plus any already counted this period) and lists the rest as waiting. Each batch
  becomes at most two units: what was admitted (pending) and what waits (held, `allowance`). The
  run translates what fits and then shows as held, with the count of what waits.
- **Waiting work is counted before it runs.** The reconciler no longer releases `allowance` holds
  itself. When a workspace has room again (a new period, an upgrade, a limit raised by staff, or
  overage on a paid plan), `AdmitWaitingWork` hands the waiting products to the same admission a
  start uses: what fits is counted and runs, a unit that only partly fits is split, the rest keeps
  waiting. The room check is only a shortcut: without it the admission would count nothing and
  release nothing.
- The 80% heads-up and the "products wait" notice say that the others are translated now; their
  "See the plans" link goes to the plans page.

## Consequences
- A product is never worked on before it is counted: every unit that runs was admitted by a start
  or by `AdmitWaitingWork`.
- A workspace over its allowance gets the allowance's worth each period instead of nothing.
- The overage fee (M7) is charged in the same admission, so both paths will charge it alike.
