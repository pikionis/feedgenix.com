---
name: feedgenix-design
description: Use this skill to generate well-branded interfaces and assets for FeedGenix, either for production or throwaway prototypes/mocks/etc. Contains essential design guidelines, colors, type, fonts, assets, and UI kit components for prototyping.
user-invocable: true
---

Read the README.md file within this skill, and explore the other available files.

- `colors_and_type.css` — the only import you need for tokens + base type
- `assets/` — logo marks (full + square + inverse)
- `preview/` — small cards showing swatches, type specimens, component atoms
- `ui_kits/marketing/` and `ui_kits/app/` — full-page HTML recreations you can lift blocks from
- `Showcase.html` — all four canonical sections (brand, marketing, pricing, dashboard) in one 680px column, suitable as a design reference for anyone building on the system

If creating visual artifacts (slides, mocks, throwaway prototypes, etc), copy assets out and create static HTML files for the user to view. If working on production code, copy assets and read the rules here to become an expert in designing with this brand.

If the user invokes this skill without any other guidance, ask them what they want to build or design, ask some questions, and act as an expert designer who outputs HTML artifacts or production code, depending on the need.

Core rules you should never violate:
- Flat only. No gradients anywhere. No shadows except overlays (menus/toasts).
- 0.5px hairline borders on everything. 2px Indigo is reserved for the Pro tier card.
- Sentence case for every string except the wordmark "FeedGenix" and proper nouns.
- Two font weights total — 400 and 500. No italics for emphasis.
- Indigo #4F46E5 and Emerald #0F9B7E are the only saturated colors. Use Sand, Mist, and Paper for warmth; Ink for dark backgrounds.
- Checkmarks in pricing lists are always emerald SVG paths (stroke 2.25), never `✓` glyphs.
- Flags are two-letter pills with tint pairs — never emoji flags.
