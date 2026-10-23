# ADR 0021 — A translation plan can carry on by itself

- Status: accepted
- Date: 2026-10-23
- Refines: ADR 0019 (catalogue sync), ADR 0020 (translation engine)

## Context
After every new file the run waited for a person to press Start (ADR 0019). A feed fetched from a
URL every day would then never be translated without someone looking every day, and its published
feeds would go stale. The legacy app translated each new file unconditionally, with no way to stop it.

## Decision
- The plan has a switch, `auto_translate`, **off** until an owner or admin turns it on (next to the
  plan's languages, fields and estimate, so it is turned on with the work in view).
- When it is on and the plan has languages, the run goes straight from reading the file to
  translating it (`CatalogSynced` → `TranslateWhenSynced` → the same `StartTranslation` a person
  presses). Only new and changed texts are work: everything fresh, kept or in the memory costs
  nothing, so a file with three changed titles is three texts.
- Nothing else changes: each call reserves its credits first; no credits, the allowance or AI
  being off hold the work exactly as for a person's start, and the run shows why.
- A save that leaves the switch out keeps it as it is.

## Consequences
- Scheduled feeds stay translated without a person, and the spend follows what changed.
- A failed reading job only ever marks a run that is still reading: a run that went on to
  translate is never marked failed by it.
