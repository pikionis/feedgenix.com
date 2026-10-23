# ADR 0016 — The credit ledger as built, and the private-beta AI budget

- Status: accepted
- Date: 2026-10-23
- Refines: ADR 0004 (AI gateway), ADR 0005 (metering, credits and pricing)

## Context
M3 needs the first paid AI calls (mapping), so the gateway and the ledger it charges are built now,
before the wallet is sold (M6). ADR 0005 fixed the rules (integer millicredits, escrow before paid
work, cost × 1.1, never shown) and said the beta is funded by the owner "under a staff-set hard
budget that goes through the same reservation path". Some choices are hard to undo once money has
moved through them, so they are recorded here.

## Decision
- **One wallet path, also for the beta.** The owner funds a beta workspace by granting it credits
  (`credits:grant TENANT EUROS --note=…`, a `grant` transaction). Purchases (M6) add to the same
  wallet. There is no separate budget table: the wallet balance *is* the hard budget. The per-plan
  `beta_ai_budget_eur_month` guard becomes a monthly top-up grant when beta customers arrive (M4).
- **Tables** (all with `tenant_id` and row-level security):
  - `credit_wallets`: `balance` and `reserved`, with `CHECK (balance >= 0)` and
    `CHECK (0 <= reserved <= balance)`.
  - `credit_reservations`: `amount`, `consumed` (`CHECK 0 <= consumed <= amount`), `open`/`settled`,
    an expiry, and a reference unique per workspace (one per work unit and attempt).
  - `credit_transactions`: append-only, enforced by a trigger that rejects every `UPDATE`; signed
    `amount`, `balance_after`, `external_ref` unique.
  - `engine_calls`: one row per attempt that reached a provider, failures included, with the tokens
    (thinking and cached too), the internal `cost_usd` and the millicredits charged.
- **Money moves at settlement.** Reserving moves `reserved` with one conditional `UPDATE … WHERE
  balance - reserved >= :amount`. Each billed attempt only adds to the reservation's `consumed`
  (row-locked, capped at `amount`). Settling charges `consumed` to the balance, releases the rest of
  the hold and writes one `ai_usage` transaction. Settling twice is a no-op. A scheduled sweep
  (`credits:settle-expired`, every five minutes) settles reservations a crashed worker never did.
- **The customer is never charged past the reservation.** The gateway checks, before the call and
  again before every attempt, that the reservation covers the attempt's worst case: every input byte
  a token (a token never covers less than one byte) plus framing, and every allowed output token
  (thinking counts inside the output limit on both providers). If a provider still reports more,
  the excess is recorded as `unbilled_millicredits` and logged; the platform absorbs it.
- **Exact arithmetic.** Prices and the FX rate are decimal strings in config; costs are computed
  with `brick/math`, × 1.1, and rounded up to a whole millicredit (€0.0000001). The 1.1 is a code
  constant (`Pricing::MARKUP`), not configuration.
- **Unpriced means not callable.** A model needs all three prices (input, output, cached input) and
  the date they were read; otherwise the router skips it, and a call with no priced model is refused
  before anything leaves. A model with a long-context price tier declares the prompt size its flat
  price holds for; a larger worst case is refused, and the caller splits the unit.
- **No model call inside a database transaction.** A rollback would erase the charge of a call the
  provider has billed, so the gateway refuses (a `LogicException`); the charge and its
  `engine_calls` row are written in their own transaction.

## Consequences
- An overdraft needs a bug in the ledger actions *and* a broken constraint; the CHECKs make it
  impossible either way, and a test drives raw `UPDATE`s into each of them.
- A run of retries or a model fallback cannot spend past what the work unit reserved.
- The multi-process concurrency test of ADR 0005 runs with the mutation-testing work in M7; until
  then the atomicity rests on single-statement conditional `UPDATE`s and row locks.
