# ADR 0008 — Stripe billing

- Status: accepted
- Date: 2026-09-28

## Context
Plans are sold monthly or yearly (yearly paid once upfront for 12 months), and credits are sold as
one-off packs (ADR 0005). The business is in the EU, so VAT applies. Payment state must never
depend on the order in which webhooks arrive.

## Decision
- **Laravel Cashier (Stripe)** for subscriptions: Checkout for monthly and yearly prices, the Customer
  Portal for card changes and cancellation, **Stripe Tax** for VAT.
- **Credit packs** (M7) use Checkout in payment mode; a top-up is credited once, keyed by the Stripe
  object id (`credit_transactions.external_ref` is unique).
- **Webhooks** are signature-verified, idempotent and order-independent: each handler re-reads the
  Stripe object's current state instead of trusting the event payload order. Replay, duplicate and
  out-of-order deliveries are tested.
- **Plan periods:** a paid plan stays effective for 3 grace days past its paid-through date; after
  that the workspace falls back to Free. A downgrade pauses over-limit feeds and languages (least
  recently used first) and never deletes anything; the last export keeps serving.
- **Test mode until launch.** The private beta runs on Stripe test mode; beta customers are invoiced
  manually. Live mode is switched on with the move to the production server (ADR 0009).
- Until Stripe is wired (M6), staff set plans with `tenants:set-plan`.

## Consequences
- Company and VAT registration and the Stripe account are prerequisites for M6 (owner actions).
- No billing state lives only in Stripe or only in the app: both sides reconcile daily.
