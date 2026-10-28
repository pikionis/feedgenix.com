# ADR 0028 — The overage fee: charged with the count, only from available credits

- Status: accepted
- Date: 2026-10-28
- Refines: ADR 0005 (metering and pricing), ADR 0016 (credit ledger), ADR 0027 (partial admission)

## Context
ADR 0005 lets a paid plan with overage on go past its product allowance for a fee of 10 credits
a product (`plans.*.overage.credits_per_product`). Until now the products over the allowance were
only flagged in `usage_products`; nothing was charged.

## Decision
- **Charged in the admission, with the count.** One transaction locks the workspace's wallet row
  (`SELECT … FOR UPDATE`), counts the products that fit, then as many overage products as the
  credits *available* (balance less what running work holds) pay the fee of, and charges exactly
  the products it counted: a product already counted this period is never charged again, and if
  the charge fails the count is undone with it.
- **The ledger** gets a kind of its own, `overage` (a negative amount, with the products and the
  period in the note). `ChargeFee` takes it all or nothing with one conditional `UPDATE … WHERE
  balance - reserved >= :fee`, so a fee can never come out of credits held for AI work.
- **What the credits do not pay for waits**, in units held with `overage_credits` (not
  `allowance`: the cause is credits, not the period). The workspace is told once a period, with the
  fee. The reconciler never releases these holds itself (they are not in its five-minute retries):
  `AdmitWaitingWork` hands them to the same admission once credits arrive, so their fee is charged
  before they run.
- Free never pays an overage fee: over its allowance it waits (ADR 0027).

## Consequences
- Every overage product that runs was charged its fee, once.
- A short wallet stops only what it cannot pay for; the rest of the run goes on.
- The fee is a plan price in credits, shown to the customer; it is not a cost and has no markup.
