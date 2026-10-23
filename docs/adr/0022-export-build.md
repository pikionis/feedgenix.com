# ADR 0022 — The export build as built

- Status: accepted
- Date: 2026-10-23
- Refines: ADR 0007 (export profiles and feed serving)

## Context
ADR 0007 set the shape: profile = preset + dialect + columns, a checked build, a shrink guard, a
verified gzip, immutable files and a pointer. The legacy exporter rewrote values to stay safe in
its own format (every `"` became `'`, so 23" became 23'), let 1% of the rows go missing in its
check, could serve a new file with an old `.gz`, and wrote empty columns that wiped a channel's
own data when it merged feeds.

## Decision
- **Dialects:** `csv` is RFC 4180 (quote a cell with a comma, quote or line break, double its
  quotes, CRLF, UTF-8 without BOM): every value is written unchanged. `tsv` has no quoting: tabs
  and line breaks in texts become one space; a golden value holding one cannot be written
  unchanged, so its row is left out and the report names it. Each dialect reads its own files back
  for the check.
- **Columns:** a preset lists them (`config/export.php`: `standard` writes every field the
  mapping fills, `google_merchant` the channel's columns). A column whose field the feed does not
  fill is left out, unless the channel requires it; the report counts the rows missing a required
  value per file. Texts are written per language: one file per language, or one column per
  language named `field_locale` (the wide layout).
- **Texts published:** the feed's own, and translations that are ready (by AI, from the memory,
  kept, approved or written by a person). Never a stale, failed or waiting translation, and never
  another language instead: the cell stays empty.
- **Transforms** reshape texts only (`plain_text`, `html_canonical` from the legacy
  DescriptionFormatter, `short` from the legacy ShortDescriptionMaker, `category_path`,
  `availability`). The one transform allowed on a golden value is `with_currency`: the price
  **byte for byte**, a space and the currency, because Google Merchant requires "189.90 CHF". A
  preset that would apply anything else to a golden field is refused.
- **Build:** keyset chunks of 500 → read back through the dialect (header, cells per row, a SKU
  in every row, exactly the rows written) → identical content to the published build means
  `unchanged` (no new key, the ETag stays) → shrink guard (under 70% of a published build of more
  than 50 rows: `rejected`, unless a person publishes it) → gzip, decompressed and compared byte
  for byte → the scratch directory renamed to its key → the pointer moved in one transaction. The
  plain file and its gzip are always one build. One build per profile at a time (a partial unique
  index), and a failed build is recorded and serves nothing.
- **Retention:** the published build and the two before it keep their files, to go back to one;
  every build keeps its record.

## Consequences
- What a channel pulls is a whole, checked build, or the previous one.
- The owner rule on golden fields holds with one visible exception, `with_currency`, which adds
  to a price and never changes it (a test fails if it does).
