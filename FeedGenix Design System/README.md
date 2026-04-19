# FeedGenix Design System

**FeedGenix** (feedgenix.com) is an AI-powered product feed intelligence SaaS for cross-border e-commerce merchants. Merchants upload CSV/XML product catalogs (up to 10,000 rows per batch) and FeedGenix translates, enriches, and optimizes them — with SHA‑256 hash-based caching, smart routing across free-tier APIs plus Claude Opus and Grok, and a **"Golden Rule"** that guarantees prices, stock, barcodes, SKUs, and dimensions are never touched by AI.

**Stack:** Laravel 11 API + React 18 (Vite + TypeScript + Tailwind + shadcn/ui).
**Based in:** Zürich, Switzerland.

---

## Sources consulted

| Source | Path / URL | What it gave us |
|---|---|---|
| User brief | The task prompt | Brand palette, tagline, four-section layout, pricing tiers, dashboard spec |
| Uploaded logo | `uploads/feedgenix-logo-wordmark.svg` | Confirmed mark geometry (3 indigo bars + emerald dot) and wordmark |
| GitHub repo | `pikionis/feedgenix.com` → `CLAUDE.md` | Product features, data model, tier definitions, Golden Rule pattern list, copy ("Golden Rule", "bulk translation", "cross-border") |

The GitHub repo's `CLAUDE.md` suggested an electric-teal palette and Plus Jakarta Sans / DM Sans; **the user's brief supersedes that** and pins the brand to Indigo #4F46E5 + Emerald #0F9B7E with a single geometric sans (Inter, weights 400 + 500). The repo's backend code was scaffolded Laravel with no frontend components, so there was no existing UI to mirror — the visuals here are a fresh system built to spec.

---

## Product context

FeedGenix has two primary surfaces:

1. **Marketing website** (`feedgenix.com`) — public landing with hero, feature cards, "how it works", pricing, footer. Conversion target: *Start free — 500 rows*.
2. **Workspace app** (`app.feedgenix.com/workspace/{team}/…`) — authenticated multi-tenant workspace: Feeds list, Jobs, Translations, Field mapping, Usage, API keys.

Supporting surfaces mentioned in code but not mocked here: Filament admin at `/admin` (super-admins only) and a Stripe-powered billing portal.

### Key concepts the design must surface

- **Feeds** — a merchant's product catalog (a CSV/XML file + its parsed rows).
- **Jobs** — background translation or mapping runs; they have progress, cost, cache-hit rate.
- **Golden Rule** — hard-coded list of field patterns (price, stock, EAN, SKU, weight, dimensions…) that AI is never allowed to modify. This is a trust anchor, not a feature — surface it as a *promise*, not a checkbox.
- **Smart cache** — every translation is keyed by `SHA‑256(source + target_lang + provider)`. Cache-hit rate is the main cost-efficiency metric on the dashboard.
- **Smart routing** — free providers (Microsoft, DeepL free tier) tried before paid (Claude Opus, Grok).
- **Tiers** — Free / Starter €29 / Pro €99 (most popular) / Enterprise.

---

## Content fundamentals

**Voice:** calm, precise, a little Swiss. We sound like an infrastructure tool a serious merchant would trust, not a growth-hacker product. No exclamation marks. No "wow", no "supercharge", no "game-changer". Claims are quantitative where possible: row counts, percent cached, euro per thousand rows.

**Person:** second-person ("you", "your buyers", "your catalog") for marketing; imperative for in-product actions ("Upload CSV", "Review mapping", "Run delta job only"). Never "we".

**Casing:** sentence case everywhere — headlines, nav links, buttons, table headers, toasts. The only exceptions are the wordmark **FeedGenix**, proper nouns (Claude Opus, Grok, Shopify, Zürich), and file-format tokens (CSV, XML, API, SOC 2, SSO). Never ALL CAPS, not even eyebrows — we use color + weight instead.

**Punctuation:**
- Em-dashes with spaces around them — like this.
- Middle dot `·` separates nav links, trust-row items, and metadata (e.g. `4,832 rows · 0.38 ¢/row · cached 61%`).
- Arrows: `→` for "how it works" flow; `↑` / `↓` for deltas (`+412`, `−€0.80`).
- Numbers use European-style grouping (commas for thousands in English copy, e.g. `28,411`); currency is `€` prefix with no space (`€29`, `€3.40`).

**Emoji & unicode:** one sparkle `✦` used exactly once — in the eyebrow pill "✦ AI-native feed platform". Checkmarks in pricing are drawn as SVG in emerald, not `✓` glyph. Flags are two-letter monograms (DE, FR, IT, EL) in colored pills, never emoji flags.

**Example copy — on tone**

| Bad (too loud) | Good (FeedGenix) |
|---|---|
| "Supercharge your catalog with AI magic!" | "Ship your catalog in every language your buyers shop in." |
| "100% safe — we NEVER break your data" | "Prices, stock and barcodes pass through untouched. That's the Golden Rule." |
| "Save money with our smart cache 💰" | "Cache hit rate 68% · €3.40 per 1,000 rows" |

---

## Visual foundations

**System in one line:** flat, warm-neutral surfaces, 0.5px indigo-tinted hairlines, generous whitespace, two type weights. Think Stripe's density × Linear's calm × a Scandinavian stationery brand's warmth.

### Color
- **Indigo #4F46E5** is the only "loud" color. It's used for the primary CTA, active-nav indicator, progress-bar fills, the Pro card border, and the logo bars. Never as a full-bleed background.
- **Emerald #0F9B7E** is reserved: checkmarks, positive deltas ("+412"), "Synced" status, and the logo spark. Treat it as confirmation, not decoration.
- **Ink #1F1D2E** is the text color and the only dark background — used for the terminal code preview and the footer.
- **Sand #FAEEDA** is the warm surface for feature-card sections and the "Most popular" pill fill. Use where we want warmth.
- **Mist #F1EFE8** is the neutral workspace surface (sidebar, stat-card fills).
- **Paper #FFFFFF** is the canvas.

### Type
- One typeface: **Inter** (geometric sans stand-in; see font note below). Two weights: 400 regular, 500 medium. No italics for emphasis — use color or weight instead. Exception: emphasized quote-style phrases in marketing copy (the tagline, pull-quotes) may use *italic regular* sparingly.
- Letter-spacing: `-0.02em` on display + wordmark, `-0.01em` on h1/h2, `0` on body, `+0.04em` on the one eyebrow pill.
- Line-height: 1.1 display, 1.25 headings, 1.5 body, 1.65 long-form paragraphs.
- Monospace: **JetBrains Mono** for the terminal code preview, SKUs, cache keys, and any code snippet. Same two weights.

### Spacing & layout
- 4px grid. Standard rhythm: 8 / 12 / 16 / 24 / 32 / 48 / 64 / 96.
- Marketing section padding: 64–96px vertical.
- App page padding: 24–32px.
- Card internal padding: 20–24px.
- Sidebar width: 180px (as specified). Stat-card gap: 12px.

### Borders
- **One border style: 0.5px solid `rgba(31,29,46,0.10)`** — we call it the *hairline*. It sits on every card, input, table row divider, browser chrome, and section edge.
- The Pro pricing card uses a **2px solid Indigo** border — this is the *only* place a thicker border appears. It signals "this is the answer".
- Inputs on focus: 1px indigo ring (no glow).

### Corners
- 8px on inputs, table rows, small pills
- 10px on stat cards and nav buttons
- 12px on feature cards, pricing cards, browser chrome
- Pill (999px) on status badges, language pills, and the eyebrow

### Backgrounds
- **No gradients. Ever.** Not on CTAs, not on cards, not on hero sections.
- **No shadows.** The one exception is dropdown menus / toasts (overlays), which may use `0 8px 24px rgba(31,29,46,0.08)`. Everything else is flat.
- **No patterns / textures / illustrations.** The hero is white with a dark code-preview card — that's the whole composition. Feature sections use warm Sand or Mist fills to create rhythm, never imagery.
- **Full-bleed imagery:** not used. This is a data product; charts and tables are the imagery.

### Imagery & iconography
- **No photos.** When a partner logo or avatar is needed, use an *initials avatar*: a 32px rounded-8px square filled with Mist, Ink-700 text, weight 500. See the feeds-table avatars.
- **Icons** are from **Lucide** at stroke-width 1.5, color inherited. Never filled icons. Never colored icons (except emerald checkmarks in pricing).
- **Flags** are two-letter pills with a language-specific tint (see CSS `--lang-*` vars), not glyph flags.

### Animation & hover
- **Default transition:** `200ms cubic-bezier(0.2, 0.6, 0.2, 1)` on color + background + border only. Never on `transform` for general UI.
- **Hover:** primary buttons darken by one step (Indigo → Indigo-600, Emerald → Emerald-600). Secondary/ghost buttons swap background from transparent to Mist. Nav links move from Ink-500 to Ink. **No underlines appear on hover** — the text is the target.
- **Press:** 70ms bg-color step darker. No scale transforms.
- **Focus:** 2px Indigo outline offset 2px. Keyboard-only via `:focus-visible`.
- **Loading:** 1.2s linear indigo progress bar (used in the "Translating" status). No spinners.
- No bounce, no parallax, no page-enter animations.

### Transparency / blur
- Not used. We're a flat system — a card is either opaque Paper, Mist, Sand, or Ink. The only alpha in the system is hairline border + ink-tinted muted text.

### Cards
- Paper card on Mist or Sand section → hairline border, 12px radius, 20–24px padding. Flat.
- Dark terminal card: `bg-ink`, 12px radius, hairline in `rgba(255,255,255,0.08)`, JetBrains Mono inside.
- Stat card: Paper on Mist, 10px radius, hairline, 20px padding, one-line label above big number above delta pill.

### Dark mode
- Canvas becomes `#15131F`, surfaces shift to `#1F1D2E` / `#2A2438`, text to `#F1EFE8`. Indigo and Emerald stay exactly the same — they have enough contrast on both. Checkmarks, deltas, status pills keep their tint (bg shifts darker, fg shifts lighter). See `colors_and_type.css` for the full token set.

### Layout rules
- Marketing nav is *not* sticky — it scrolls off. The app top bar is sticky.
- Footers use Ink background with Paper-muted text.
- Max content width in marketing: 1200px centered. App is full-width minus the 180px sidebar.

---

## Iconography

FeedGenix has no custom icon set. We use **[Lucide](https://lucide.dev)** (CDN: `https://unpkg.com/lucide-static@latest/icons/`) as the default library — stroke width 1.5, color inherits from `currentColor`, 16px default size, 20px in the sidebar.

- Copied statically into `assets/icons/` for offline use (see list below).
- Flags are *not* icons — they're 2-letter pills (see the dashboard feed table).
- The sparkle `✦` is a literal unicode character, used exactly once in the hero eyebrow.
- Emerald checkmarks in pricing are inline SVG paths, not the Lucide `check` — they're slightly thicker (stroke 2.25) to read at small sizes in the feature-list rhythm.

**Substitution flag:** Lucide is a substitution — the codebase had no icons yet. It's a clean match for the system (geometric, 1.5px stroke, flat). If a custom icon set is introduced later, swap the `assets/icons/` folder.

---

## Font note — please supply or confirm

The brief asks for "a clean geometric sans". Without a licensed custom face, we use **Inter** (Google Fonts) as the system font for both display and body, and **JetBrains Mono** (Google Fonts) for code. Inter has slightly more humanist proportions than a pure geometric sans (Aeonik, Söhne, Neue Haas Grotesk Display would be closer).

**If you have a licensed face in mind (Aeonik, Söhne, GT America, Neue Montreal, etc.), drop the woff2 files into `fonts/` and we'll swap the `@import` in `colors_and_type.css`.** Until then, Inter is the source of truth — all specimens, mocks, and UI kits use it.

---

## Index — what's in this folder

```
FeedGenix-Design-System/
├── README.md                         ← you are here
├── SKILL.md                          ← invoke instructions (Claude Code compatible)
├── colors_and_type.css               ← the one CSS import — tokens + type
├── assets/
│   ├── feedgenix-logo-wordmark.svg   ← full horizontal lockup
│   ├── feedgenix-mark.svg            ← square mark (bars + dot)
│   ├── feedgenix-mark-inverse.svg    ← mark for dark backgrounds
│   └── icons/                        ← Lucide SVGs used in UI kits
├── preview/                          ← small cards surfaced in the Design System tab
│   ├── brand-lockup.html
│   ├── brand-mark-sizes.html
│   ├── color-brand.html
│   ├── color-surfaces.html
│   ├── color-semantic.html
│   ├── color-language.html
│   ├── type-display.html
│   ├── type-body.html
│   ├── type-mono.html
│   ├── type-scale.html
│   ├── spacing-scale.html
│   ├── radius-scale.html
│   ├── elevation.html
│   ├── component-buttons.html
│   ├── component-inputs.html
│   ├── component-badges.html
│   ├── component-stat-card.html
│   ├── component-feed-row.html
│   ├── component-pricing-pro.html
│   └── component-nav.html
└── ui_kits/
    ├── marketing/
    │   ├── README.md
    │   ├── index.html                ← full marketing page, tweakable
    │   └── components/
    │       ├── BrowserChrome.jsx
    │       ├── TopNav.jsx
    │       ├── Hero.jsx
    │       ├── TerminalPreview.jsx
    │       ├── FeatureCards.jsx
    │       ├── HowItWorks.jsx
    │       ├── Pricing.jsx
    │       └── Footer.jsx
    └── app/
        ├── README.md
        ├── index.html                ← full workspace dashboard
        └── components/
            ├── Sidebar.jsx
            ├── QuotaWidget.jsx
            ├── PageHeader.jsx
            ├── StatCard.jsx
            ├── FeedsTable.jsx
            └── DeltaCallout.jsx
```

The **"Showcase"** (the single stacked 680px-wide deliverable from the brief) lives at the project root as `Showcase.html` — it composes all four sections (brand identity, marketing, pricing, dashboard) into one scrollable document.
