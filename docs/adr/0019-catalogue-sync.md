# ADR 0019 — The catalogue sync: the feed is the truth, one path, guarded removals

- Status: accepted
- Date: 2026-10-23
- Refines: ADR 0003 (product_texts), ADR 0006 (pipeline)

## Context
The legacy scanner merged every new file into the old data (`array_merge`), so a value the supplier
removed was kept for ever; it had two code paths, the parallel one without the disappear guard
(D10); it unset language columns whose texts looked identical (D9); it dropped a literal "0" and
translated placeholders such as "n/a".

## Decision
- **What the feed says now is what the catalogue holds.** A blanked cell removes the value; a
  removed source text makes its translations stale.
- **Golden and pass-through values are copied byte for byte** into `products.source_values`
  (spaces, leading zeros, "0", "n/a", any script). Only a cell that is blank is absent.
- **Texts are kept unless effectively empty**: blank, a placeholder ("n/a", "-", "keine Angabe"…),
  or markup with no text. Every language column is kept as mapped, whatever it holds.
- **A changed source makes its translations stale** (`product_texts.status = stale`), compared by
  the hash and language of the source they were made from. A translation a person wrote is marked
  stale too, and never overwritten automatically. A language the feed now provides itself replaces
  the translation for it.
- **One path, in chunks of 500 rows** with bulk upserts; no long transactions. Rows without a SKU
  and repeated SKUs (the last row wins) are counted on the run.
- **Products the file no longer lists are marked gone** (`disappeared`), unless that would be more
  than 30% of a catalogue of over 50; then nothing is removed and the run carries a warning. A sync
  that fails part way never reaches this step.
- **When it runs:** on a mapping's approval, and on every new file whose columns have an approved
  mapping. A run waiting for a decision is superseded by the new file; after the sync the run
  waits for the translation plan (`stage = plan`, `awaiting_decision`).

## Consequences
- A supplier can correct or clear data, and the catalogue follows.
- A truncated or partial file can never wipe a catalogue silently.
