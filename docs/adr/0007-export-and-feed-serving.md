# ADR 0007 — Export profiles and feed serving

- Status: accepted
- Date: 2026-09-28

## Context
Sales channels pull feed URLs on their own schedule. The legacy app once served a silently truncated
gzip (row 218K of a larger feed) and once published a catalogue that had lost most of its rows.
A bad file at a feed URL is immediately a bad shop listing.

## Decision
- **An export profile = preset + dialect + columns.**
  - *Preset:* required fields, default columns, value maps (non-golden fields only).
  - *Dialect:* serialisation only — `Rfc4180Csv`, and a tab-separated dialect that collapses
    `\r\n\t` to spaces (literal newlines once split rows in the legacy feeds).
  - *Columns:* `{header, field, lang | *, transform}`; layouts `wide` (one column per language) or
    `per_language` (one file per language).
- **Golden values are never rewritten.** If a dialect would change a golden value, the row goes to
  the export report instead.
- **Build:** keyset-paginated writer → validation → **shrink guard** (reject when the new file has
  fewer than 70% of the previous rows and the previous had more than 50) → gzip, **verified by
  decompressing it** → write to a new immutable key → swap the pointer in the database.
- **Serving** at `/f/{token}/{file}[.gz]` through nginx `X-Accel-Redirect` with ETags. `503` with
  `Retry-After` while nothing is published. A request never triggers a build.
- **Feed tokens** are stored hashed (lookup) and encrypted (display), rotatable with a grace period;
  a rotated token returns 404 after the grace. Feed responses carry `noindex` and are rate limited.
- **A paused feed keeps serving its last export** (downgrades never break a live shop).

## Consequences
- A killed build can never serve a partial file; the previous artifact keeps serving.
- Moving exports to object storage later only swaps the `ArtifactServer`.
- Golden-file tests cover every preset × dialect.
