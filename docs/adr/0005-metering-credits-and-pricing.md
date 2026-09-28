# ADR 0005 — Metering, credits and pricing

- Status: accepted
- Date: 2026-09-28

## Context
The owner's business model (2026-09-28): the subscription is the platform fee and the owner's
revenue; the customer always pays for AI with prepaid credits. A customer who stops paying must never
leave unpaid work behind.

## Decision
- **Plans** (config `plans.php`): Free / Pro €40 / Ultra €100 / Custom €180 per month. Yearly =
  12 × (monthly − 25%), paid once, 12 months' access, **one annual product pool**. Limits: feeds
  1/5/20/∞, languages 1/5/10/∞, products per month 500 / 50k / ∞ / ∞, worker slots 1/2/4/8.
  The subscription includes **no AI spend**.
- **Credits.** 1 credit = €0.0001 (config), so 100,000 credits = €10. Packs of €10 / €50 / €100 or a
  custom amount of at least €10; 10,000 welcome credits once per verified account; no expiry.
- **Pricing rule (owner, fixed):** every AI call, on every model, is charged at exactly
  **provider cost × 1.1** — computed from the provider's reported usage for that call (every billed
  token, thinking tokens included, and every billed attempt) at the current FX rate. The public price
  list (credits per 1K input/output tokens per model, with "best for / why pick it") is derived with
  the same formula and recomputed when a provider price or the FX rate changes.
  **Cost, the markup and provider prices never appear in any response, page or email.**
- **Ledger in integer millicredits:** `credit_wallets` (balance and reserved, both `CHECK >= 0`),
  `credit_reservations` (unique per work unit and attempt, with expiry), append-only
  `credit_transactions` (`external_ref` unique). Every counter moves with a conditional
  `UPDATE … WHERE balance >= :amount`; no application locks.
- **Escrow before paid work:** estimate the run, reserve the estimate × 1.3, run, settle the actual,
  release the rest. The gateway refuses calls without a reservation. Short balance → the run waits
  with the exact numbers and a top-up button.
- **Allowance:** "products" = new or changed products that need AI work, each counted once per month
  (`usage_product_months` primary key: tenant, month, product), also inside a yearly pool. Above it,
  paid plans continue with an overage fee of 10 credits per product; Free waits for the next month or
  upgrades. Translation-memory hits cost nothing.
- **The customer is always told:** 80% heads-up, a pre-run estimate, held work with the exact counts
  and the choices (wait / continue with credits / top up / upgrade), low-balance heads-up.
- **Before the wallet ships (private beta),** AI for beta workspaces is funded by the owner under a
  staff-set hard budget that goes through the same reservation path.

## Consequences
- A snapshot test over every API resource and email asserts that no cost, markup or provider price is
  exposed; a concurrency test proves reservations never overdraw.
- Mutation testing on the ledger (score ≥ 90%) is part of M7's definition of done.
