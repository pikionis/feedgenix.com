# ADR 0025 — Notices: told once, with the choices

- Status: accepted
- Date: 2026-10-28
- Refines: ADR 0005 (metering), ADR 0024 (plan pauses)

## Context
Work that waits (for credits, the allowance, a plan pause) and an export held back by the shrink
guard need a person's decision, but nothing told anyone: they had to open the right page. The
legacy app sent operator alerts by email to everyone, for every event.

## Decision
- **A notice** (`notices`) belongs to a workspace: a level (info, warn, action), a title, a body
  with the numbers and dates, and one way to act on it (a label and an app path). Everyone in the
  workspace sees the same notices; the latest 30 are listed at `/app/notices`, the unread count
  next to Notices in the menu. Opening the page marks what it shows as read.
- **Once:** each notice has a key (`usage_80:{period}`, `held_credits:{run}`,
  `export_rejected:{build}`…), so the same thing is never told twice.
- **By email** when it needs a decision (level `action`, and the 80% heads-up), to the owner and
  admins with a verified address. The same words as in the app.
- **What is told:** 80% of the period's products used, with what happens after that on this plan;
  products waiting for the next period, with the date and the upgrade; translations waiting for
  credits, once per run; what a plan paused, by name, and that nothing is deleted (by email), and
  what it resumed (in the app only); an export build held back by the shrink guard.
- **Before a run:** the plan page says what a start would count against the period's products, and
  what would be over the allowance, from the same count the start makes (`Admission::preview`).
- Never a cost, a markup or a provider price: only products, dates, plans and credits.
- The module is `Domain/Notices`; others call `Notify::send`, and it listens to Billing's
  `PlanPausesChanged`.

## Consequences
- A person learns about waiting work when it starts waiting, not when a channel complains.
- Email is sent through Laravel's mailer: the log until Postmark is set up.
