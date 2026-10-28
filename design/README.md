# FeedGenix design system

The visual identity carried over from the April 2026 prototype (git tag `prototype-2026-04`,
`FeedGenix Design System/`). Only the design rules are kept here; that prototype's product facts
(tiers, providers, stack) are obsolete.

- **Tokens:** `frontend/src/styles/tokens.css` is the single source of truth (colours, surfaces,
  status, language pills, radius, motion). Tailwind's theme maps onto them in `frontend/src/app.css`.
- **Logos:** `logo/feedgenix-mark.svg` (three indigo bars + the emerald spark),
  `logo/feedgenix-mark-inverse.svg` (for dark backgrounds), `logo/feedgenix-logo-wordmark.svg`.
  In the app the mark is inlined by `frontend/src/components/brand/logo.tsx`.
- **Fonts:** Inter (400, 500) and JetBrains Mono, self-hosted through `@fontsource`. Never loaded
  from Google.

## The landing page
`frontend/src/routes/home.tsx` and `frontend/src/features/marketing/` implement the prototype's
marketing UI kit (`ui_kits/marketing/index.html`): nav, hero with the feed preview, feature cards on
the Sand band, how it works, pricing and the Ink footer. The look is the kit's; the copy is not.
- **Only true claims.** The kit's placeholders are gone and must not come back: review scores, a
  certification, a company name and register number, a demo that does not exist, a former customer's
  file name, a CLI. Company and legal details arrive with the public launch (M11). A test fails on
  any of them.
- **Prices are read live from `/api/plans`.** The page never shows a cost per row, a provider price
  or the credit markup (owner's pricing rule); a test fails if it does.
- **Layout widths stay in px** (1200, 1120, 520, 340). The root font is 15px, so the rem classes an
  editor suggests instead render about 6% narrower.

## Voice
- Calm, precise, a little Swiss: an infrastructure tool a serious merchant would trust. No
  exclamation marks, no "wow", "supercharge" or "game-changer".
- Claims are quantitative where possible: rows, percent from memory, euro per thousand products.
- Second person for marketing ("your catalog"); imperative in the product ("Upload CSV",
  "Review mapping"). Never "we".
- **Sentence case everywhere** (headlines, buttons, table headers, toasts). Exceptions: the wordmark
  **FeedGenix**, proper nouns and format tokens (CSV, XML, API). Never ALL CAPS.
- Em-dashes with spaces — like this. Middle dot `·` separates metadata
  (`4,832 rows · 61% from memory`). `→` for flows, `↑`/`↓` for deltas.
- Numbers: commas for thousands in English copy (`28,411`); currency is a `€` prefix with no space
  (`€40`, `€3.40`).
- Language pills are two-letter monograms in tinted pills, never emoji flags.

| Too loud | FeedGenix |
|---|---|
| "Supercharge your catalog with AI magic!" | "Ship your catalog in every language your buyers shop in." |
| "100% safe — we NEVER break your data" | "Prices, stock and barcodes pass through untouched. That's the Golden Rule." |

## Visual rules
- **Flat.** Warm-neutral surfaces, 0.5px hairlines, generous whitespace, two type weights.
- **Colour.** Indigo `#4F46E5` is the only loud colour: primary action, active navigation, progress,
  the recommended plan's border, the logo bars — never a full-bleed background. Emerald `#0F9B7E`
  means confirmation only (checkmarks, positive deltas, "synced", the spark). Ink `#1F1D2E` is text
  and the only dark background. Sand for warm sections, Mist for the workspace surface, Paper as the
  canvas.
- **Borders.** One style: 0.5px solid hairline. The only thicker border is the recommended plan card
  (2px Indigo). Inputs on focus: 1px Indigo ring, no glow.
- **Corners.** 8px inputs and small pills, 10px stat cards and nav buttons, 12px cards and pricing
  cards, pills fully rounded.
- **No gradients, no shadows** (overlays such as menus and toasts only), no patterns, no stock
  photos. Charts and tables are the imagery.
- **Icons:** Lucide, stroke width 1.5, colour inherited, never filled or coloured (except the emerald
  pricing checkmarks, drawn slightly thicker).
- **Motion:** 200ms `cubic-bezier(0.2, 0.6, 0.2, 1)` on colour, background and border only. Hover
  darkens one step; no transforms, no bounce, no page-enter animations. Loading is a thin indigo
  progress bar, never a spinner. Focus: 2px Indigo outline, offset 2px, keyboard only.
- **Layout:** marketing max width 1200px, section padding 64–96px; app page padding 24–32px, cards
  20–24px; sidebar 180px; the app top bar is sticky, the marketing nav is not.
- **Dark mode:** canvas `#15131F`, surfaces `#1F1D2E` / `#2A2438`, text `#F1EFE8`. Indigo and Emerald
  stay the same; status pills keep their tint (background darker, text lighter).

## The workspace (app kit)
The app follows `ui_kits/app` of the design system: a Mist sidebar on a white canvas, and no white
cards on white. Each page is built from the same few parts in `frontend/src/components/ui/`:

- `PageHeader`: the way back, the title (26px, 500), one line under it, the actions on the right.
- `StatCard`: a number that matters, on Mist, 10px corners, no border.
- `Section` and `Rows`: a heading with its content; rows in one hairline frame instead of a card each.
- `Table` (`THead`, `TH`, `TR`, `TD`): a hairline frame, a quiet `#FAFAF7` header row, numbers
  right-aligned in mono.
- `Callout`: the next thing to do, in a dashed indigo frame, with one action.
- `Initials`: a Mist (or Ink, for the workspace) square instead of a photo.
- `LanguagePill`: the two-letter tint per language, never a flag.
- `FeedStateBadge` (`features/feeds/FeedState.tsx`): one word or two with a coloured dot; the words
  stay in the text colour.

Facts about a thing (what a file holds, a run's progress, the figures of a plan) sit in a Mist band;
lists and tables sit in hairline frames. `npm run build` then
`FEEDGENIX_SCREENS=1 E2E_API_PORT=8010 npx playwright test e2e/screens.spec.ts` shoots every page at
desktop and phone width into `frontend/screens/` (ignored by git) for a design review.
