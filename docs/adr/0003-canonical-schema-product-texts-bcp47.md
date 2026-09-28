# ADR 0003 — Canonical schema, `product_texts` and BCP-47 languages

- Status: accepted
- Date: 2026-09-28

## Context
The legacy app stored every translation of a product in one JSON column, keyed by Channable's French
column names and four fixed languages. Parallel workers, re-imports and manual edits read, modified
and rewrote the same blob, which caused lost updates and three separate data-loss incidents.

## Decision
- **Canonical fields with English keys**, grouped by class:
  - *Golden* (byte-identical, never in prompts): sku, parent_sku, gtin, mpn, price, sale_price,
    currency, stock_quantity, weight, dimensions.
  - *Pass-through:* availability, brand, condition, link, image links, custom_label_0–4.
  - *Translatable* (keep / translate / optimize): title, description (HTML), color, size (keep by
    default), category, warranty.
  - *Translatable and composable:* features, material, care_instructions, dimensions_text,
    other_attributes.
  - *Optimize-only* (Pro): short and meta fields. *Derived:* google_product_category.
  Tenants can add custom fields (rows in `target_fields` with their `tenant_id`).
- **Languages are BCP-47 codes** (`de-CH`, `pt-BR`…) from a `languages` table; each workspace picks
  its targets up to its plan limit. Regional rules (e.g. Swiss German "ss") are per-locale behaviour.
- **`product_texts`: one row per product × field × language × role** (source or target), with a
  status (pending, ready, stale, qa_failed, held), an origin (source, composed, ai, tm, manual,
  approved), the `source_hash` it was produced from, the style hash, prompt version and model.
- **Fenced writes.** A result is written only if the source is still the one it was computed from and
  a person has not edited it:
  `UPDATE … WHERE source_hash = :hash AND origin <> 'manual'`.
- Golden and pass-through values live in `products.attributes` (JSONB) as the exact source strings.
  The raw rows stay in the immutable import file, which is what a remap replays.

## Consequences
- No shared document: sync, parallel units, manual edits and invalidation touch only their own rows.
- Pending work is found through a partial index (cost proportional to the work, not the catalogue).
- Per-cell provenance feeds the translation memory, the review inbox and the audit trail.
