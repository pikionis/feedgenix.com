# Marketing UI kit — feedgenix.com

Public marketing surface: top nav, hero, terminal preview, feature cards, how-it-works, pricing, footer. Fully realized at the correct ~1200px width in `index.html`; the stacked 680px version lives at the project root (`Showcase.html`).

This kit is a static HTML recreation — the repo's frontend had not been built when the system was authored, so the source of truth for visuals is the brief + tokens in `colors_and_type.css`.

## Files
- `index.html` — full marketing page at 1200px
- Components are inlined in index.html for portability; the structure mirrors the eventual React breakdown: `TopNav`, `Hero`, `TerminalPreview`, `FeatureCards`, `HowItWorks`, `Pricing`, `Footer`.
