# ADR 0018 — Mapping: drafts by column signature, AI per column, one pipeline

- Status: accepted
- Date: 2026-10-23
- Refines: ADR 0003 (canonical schema), ADR 0004 (AI gateway)

## Context
A mapping decides which feed column fills which canonical field, so a wrong one corrupts a shop's
catalogue at scale. The legacy mapper reused a mapping by file id across uploads with other columns
(D6), skipped its own validation on refine and save (D7), only knew Latin letters (D8), and sent the
first three raw rows, prices and barcodes included, to the model.

## Decision
- **A mapping belongs to a feed and a column signature** (`mappings`, unique per feed and
  signature), never to a file. Imports with the same columns share it; new columns get a new draft.
- **The first draft is free and has no AI:** the profiler's SKU choice, value kinds (barcodes,
  image links) and each field's column hints in several languages. It is drafted on first view.
- **The AI suggests per column** (`map@1`, purpose `mapping`): each column is one gateway item with
  its own id, answered with a field, a role (`direct`, or `part` of a composed field), a locale, a
  label, a confidence and a reason. Parts of one composable field and locale become one recipe.
  The worst case is reserved before the job runs; the reservation is settled after it.
- **Protected columns** (prices, stock, ids and barcodes, images and links, weights: by name as
  words, or by values that are numbers, codes, barcodes or URLs) never show their values to a model
  and never feed a text field or a composition. Numeric measurement columns are golden too, so
  `dimensions_text` is only filled from text.
- **One pipeline for every change** (`MappingPipeline`): heuristics, AI suggestions, refinements
  and a person's edits all pass the same checks (the field exists, the column exists, the golden
  rule, value validation, composition gates), and the stored mapping is always what it decided.
  - A value check that fails leaves the field empty with the reason (`rejected`).
  - Two close suggestions for different columns leave it empty for a person to choose.
  - A person's decision, filled or cleared, is never overridden by a later suggestion.
  - Any change sends an approved mapping back to draft; edits carry the version they were made on.
- **Approval needs the SKU and one text field.** Everything else may stay empty.

## Consequences
- The review screen shows, per field, where its value comes from, sample values, the status and the
  reason, including what was refused and why.
- A property test proves that no protected value ever reaches a prompt.
- Changing the canonical fields or their hints changes future drafts only; stored mappings keep
  their decisions until they are edited or suggested again.
