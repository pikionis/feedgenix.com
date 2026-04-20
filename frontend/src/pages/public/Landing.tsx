import { Link } from 'react-router-dom';
import { useEffect, useRef, useState } from 'react';

/**
 * Marketing landing — Mosaic variant.
 *
 * Layout mirrors `FeedGenix Design System/ui_kits/marketing/Mosaic.html`:
 * Hero with 96px display type + color-block mosaic on a 40px grid, stats,
 * logo strip, auto-rotating flip cards, terminal preview, features, how it
 * works, golden-rule trust anchor, pricing, emerald testimonial block, 2-col
 * FAQ. Flat throughout — no gradients, no shadows, 0.5px hairlines only.
 */

export default function Landing() {
  return (
    <>
      <Hero />
      <StatStrip />
      <LogoStrip />
      <FlipCards />
      <TerminalPreview />
      <Features />
      <HowItWorks />
      <GoldenRule />
      <PricingPreview />
      <Testimonials />
      <FAQ />
    </>
  );
}

/* ───────────────────────── HERO ───────────────────────── */

function Hero() {
  return (
    <section className="relative overflow-hidden bg-mist px-10 pb-20 pt-10" style={{ minHeight: 620 }}>
      <GridBackground />
      <MosaicBlocks />

      <div className="relative mx-auto max-w-280">
        <div className="relative z-10 mb-5 inline-flex items-center gap-1.5 rounded-full border-[0.5px] border-ink/14 bg-paper px-3 py-1.25 text-[12.5px] font-medium">
          <span className="h-1.5 w-1.5 rounded-full bg-emerald-brand" />
          <span>✦ AI-native feed platform</span>
        </div>

        <h1 className="relative z-10 mb-7 max-w-[900px] font-medium leading-[0.95] tracking-[-0.04em] text-ink"
            style={{ fontSize: 'clamp(48px, 8vw, 96px)' }}>
          Ship your
          <br />
          catalog in every
          <br />
          <HighlightWord tone="pink">language</HighlightWord> your
          <br />
          buyers shop in.
        </h1>

        <p className="relative z-10 mb-3.5 max-w-155 text-[19px] leading-[1.45] text-ink-500">
          Upload a CSV. We translate, enrich, and optimize 10,000 products per batch with
          hash-cached pricing and smart routing across Claude Opus, Grok and free-tier APIs.
        </p>
        <p className="relative z-10 mb-8 text-[19px] font-medium text-ink">
          Feeds. Shipped better.
        </p>

        <div className="relative z-10 flex flex-wrap items-center gap-3.5">
          <Link
            to="/register"
            className="inline-flex items-center gap-2 rounded-full bg-ink px-7 py-4 text-[15px] font-medium text-white transition-opacity duration-200 hover:opacity-90"
          >
            Start free — 500 rows →
          </Link>
          <a
            href="#demo"
            className="inline-flex items-center gap-1.5 text-[14px] font-medium text-ink hover:text-ink-500"
          >
            <PlayIcon /> Watch 2-min demo
          </a>
        </div>
      </div>
    </section>
  );
}

function HighlightWord({ children, tone = 'pink' }: { children: React.ReactNode; tone?: 'pink' | 'cyan' | 'amber' }) {
  const bg = tone === 'pink' ? '#F7B8D2' : tone === 'cyan' ? '#33C6E8' : '#F0B54A';
  return (
    <span className="relative inline-block rounded-md" style={{ background: bg, padding: '0 6px' }}>
      {children}
    </span>
  );
}

function GridBackground() {
  return (
    <div
      aria-hidden
      className="pointer-events-none absolute inset-0"
      style={{
        backgroundImage:
          'linear-gradient(rgba(31,29,46,0.05) 0.5px, transparent 0.5px), linear-gradient(90deg, rgba(31,29,46,0.05) 0.5px, transparent 0.5px)',
        backgroundSize: '40px 40px',
      }}
    />
  );
}

function MosaicBlocks() {
  // Positioned absolutely around the hero type. Same layout as Mosaic.html so
  // the blocks tuck behind, between, and around the headline lines.
  const blocks: Array<{ color: string; style: React.CSSProperties }> = [
    { color: '#1FB281', style: { left: -20,   top: 40,   width: 90,  height: 60 } },
    { color: '#1FB281', style: { left: 70,    top: 0,    width: 280, height: 100 } },
    { color: '#F7B8D2', style: { left: -40,   top: 200,  width: 80,  height: 28 } },
    { color: '#1FB281', style: { right: 40,   top: 20,   width: 260, height: 130 } },
    { color: '#33C6E8', style: { left: 330,   top: 230,  width: 180, height: 50 } },
    { color: '#F7B8D2', style: { right: 80,   top: 260,  width: 200, height: 80 } },
    { color: '#F0B54A', style: { right: -20,  top: 230,  width: 50,  height: 50 } },
    { color: '#F0B54A', style: { left: 380,   bottom: 60,  width: 60, height: 40 } },
    { color: '#33C6E8', style: { left: 260,   bottom: 100, width: 40, height: 40 } },
    { color: '#1FB281', style: { right: 280,  bottom: 80,  width: 60, height: 50 } },
    { color: '#F7B8D2', style: { right: 40,   bottom: 40,  width: 100, height: 50 } },
    { color: '#4F46E5', style: { left: -10,   bottom: 100, width: 28, height: 28 } },
  ];
  return (
    <div aria-hidden className="pointer-events-none absolute inset-0 mx-auto max-w-280">
      {blocks.map((b, i) => (
        <span
          key={i}
          className="absolute rounded"
          style={{ ...b.style, background: b.color, zIndex: 1 }}
        />
      ))}
    </div>
  );
}

function PlayIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor">
      <path d="M8 5v14l11-7z" />
    </svg>
  );
}

/* ───────────────────────── STAT STRIP ───────────────────────── */

function StatStrip() {
  const stats = [
    { v: '$20B+', lbl: 'catalog value processed' },
    { v: '12M+',  lbl: 'rows translated' },
    { v: '2M+',   lbl: 'SHA-256 cache hits' },
    { v: '61%',   lbl: 'avg cache hit rate' },
  ];
  return (
    <section className="bg-paper px-10 py-7 fg-hairline-t fg-hairline-b">
      <div className="mx-auto grid max-w-280 grid-cols-2 md:grid-cols-4">
        {stats.map((s, i) => (
          <div
            key={s.lbl}
            className={`px-5 text-center ${i < 3 ? 'md:border-r-[0.5px] md:border-ink/10' : ''}`}
          >
            <div className="text-[28px] font-medium leading-none tracking-[-0.02em] text-ink">
              {s.v}
            </div>
            <div className="mt-1 text-[12.5px] text-ink-500">{s.lbl}</div>
          </div>
        ))}
      </div>
    </section>
  );
}

/* ───────────────────────── LOGO STRIP ───────────────────────── */

function LogoStrip() {
  const brands = ['deindeal', 'Galaxus', 'BRACK.CH', 'Zalando', 'migros', 'manor', 'digitec', 'LeShop'];
  return (
    <section className="bg-paper px-10 py-11 text-center">
      <div className="mb-6 inline-flex items-center gap-3.5 text-[13px] text-ink-500">
        Trusted by the fastest growing merchants
        <span className="inline-flex items-center gap-1 rounded-full bg-mist px-2.5 py-0.75 text-[12px] font-medium text-ink">
          <span style={{ color: '#F0B54A' }}>★</span> 4.8 on G2
        </span>
      </div>
      <div className="mx-auto flex max-w-260 flex-wrap items-center justify-between gap-x-8 gap-y-4 opacity-60 grayscale">
        {brands.map((b) => (
          <span
            key={b}
            className="whitespace-nowrap text-[17px] font-medium tracking-[-0.01em] text-ink"
            style={b === 'Galaxus' ? { fontStyle: 'italic' } : undefined}
          >
            {b}
          </span>
        ))}
      </div>
    </section>
  );
}

/* ───────────────────────── FLIP CARDS ───────────────────────── */

type FlipFace = {
  front: {
    bg: string;
    fg: string;
    field: string;
    val: string;
    metaLeft: string;
    metaRight: string;
  };
  back: {
    field: string;
    val: string;
    flag: { label: string; bg: string; fg: string };
    ok: string;
  };
};

const FLIP_CARDS: FlipFace[] = [
  {
    front: { bg: '#FAEEDA', fg: '#1F1D2E', field: 'title_en · source', val: 'Merino wool sweater, ribbed crew neck', metaLeft: 'EN', metaRight: '#a1f4 · cached' },
    back:  { field: 'title · target', val: 'Pullover aus Merinowolle mit geripptem Rundhalsausschnitt', flag: { label: 'DE', bg: '#E7E4FA', fg: '#3B3490' }, ok: '✓ claude-opus' },
  },
  {
    front: { bg: '#F7B8D2', fg: '#6B1E3F', field: 'description_en', val: 'Lightweight · breathable · machine-washable at 30°C', metaLeft: 'EN', metaRight: '0.38 ¢/row' },
    back:  { field: 'description · target', val: 'Léger · respirant · lavable en machine à 30°C', flag: { label: 'FR', bg: '#DCEAF9', fg: '#1F4E87' }, ok: '✓ cache hit' },
  },
  {
    front: { bg: '#33C6E8', fg: '#0D3A47', field: 'category_en', val: 'Men → Outerwear → Knitwear', metaLeft: 'EN', metaRight: 'taxonomy' },
    back:  { field: 'category · target', val: 'Uomo → Abbigliamento → Maglieria', flag: { label: 'IT', bg: '#DFF1E8', fg: '#0B7A63' }, ok: '✓ grok-4' },
  },
  {
    front: { bg: '#1F1D2E', fg: '#FFFFFF', field: 'price_chf · golden rule', val: 'CHF 149.00', metaLeft: '🛡 protected', metaRight: 'never translated' },
    back:  { field: 'price_chf · unchanged', val: 'CHF 149.00', flag: { label: 'GOLDEN RULE', bg: '#FAEEDA', fg: '#A35412' }, ok: '✓ bypassed' },
  },
  {
    front: { bg: '#F0B54A', fg: '#5C3E0E', field: 'title_en', val: 'Waterproof hiking boot, vibram sole', metaLeft: 'EN', metaRight: '#c2e9 · new' },
    back:  { field: 'title · target', val: 'Αδιάβροχη μπότα πεζοπορίας με σόλα vibram', flag: { label: 'EL', bg: '#FAE9DC', fg: '#A35412' }, ok: '✓ claude-opus' },
  },
  {
    front: { bg: '#4F46E5', fg: '#FFFFFF', field: 'stock · golden rule', val: '42 units · warehouse-ZRH1', metaLeft: '🛡 protected', metaRight: 'verbatim' },
    back:  { field: 'stock · unchanged', val: '42 units · warehouse-ZRH1', flag: { label: 'GOLDEN RULE', bg: '#FAEEDA', fg: '#A35412' }, ok: '✓ bypassed' },
  },
  {
    front: { bg: '#1FB281', fg: '#0A3D2B', field: 'bullet_1', val: '100% certified organic merino from New Zealand', metaLeft: 'EN', metaRight: 'bullet list' },
    back:  { field: 'bullet_1 · target', val: 'Lana merina 100% orgánica certificada de Nueva Zelanda', flag: { label: 'ES', bg: '#FAEEDA', fg: '#A35412' }, ok: '✓ cache hit' },
  },
  {
    front: { bg: '#F7B8D2', fg: '#6B1E3F', field: 'barcode · golden rule', val: '7612345 678901', metaLeft: '🛡 protected', metaRight: 'EAN-13' },
    back:  { field: 'barcode · unchanged', val: '7612345 678901', flag: { label: 'GOLDEN RULE', bg: '#FAEEDA', fg: '#A35412' }, ok: '✓ bypassed' },
  },
];

function FlipCards() {
  // Auto-flip three cards at a time every 2.2s so the grid feels alive.
  // Individual hover/click still overrides the auto state.
  const [autoIdx, setAutoIdx] = useState(0);
  const [manual, setManual] = useState<Record<number, boolean>>({});
  const intervalRef = useRef<number | null>(null);

  useEffect(() => {
    intervalRef.current = window.setInterval(() => setAutoIdx((i) => i + 1), 2200);
    return () => {
      if (intervalRef.current) window.clearInterval(intervalRef.current);
    };
  }, []);

  function isAutoFlipped(i: number): boolean {
    const n = FLIP_CARDS.length;
    return i === autoIdx % n || i === (autoIdx + 3) % n || i === (autoIdx + 5) % n;
  }

  return (
    <section className="bg-mist px-10 py-20">
      <div className="mx-auto mb-12 max-w-[720px] text-center">
        <div className="mb-2.5 text-[12.5px] font-medium tracking-[0.04em] text-indigo">
          HOVER OR TAP TO FLIP
        </div>
        <h2 className="mb-3.5 text-[48px] font-medium leading-[1.05] tracking-[-0.03em] text-ink">
          One source row.
          <br />
          Every language your buyers shop in.
        </h2>
        <p className="text-[16px] leading-[1.5] text-ink-500">
          FeedGenix keeps your source-of-truth product, then routes each field to the right
          model — cached by SHA-256 so nothing translates twice.
        </p>
      </div>

      <div className="mx-auto grid max-w-280 grid-cols-2 gap-3.5 md:grid-cols-4">
        {FLIP_CARDS.map((card, i) => (
          <FlipCard
            key={i}
            card={card}
            flipped={manual[i] ?? isAutoFlipped(i)}
            onHover={(v) => setManual((m) => ({ ...m, [i]: v }))}
            onToggle={() => setManual((m) => ({ ...m, [i]: !m[i] }))}
          />
        ))}
      </div>
    </section>
  );
}

function FlipCard({
  card,
  flipped,
  onHover,
  onToggle,
}: {
  card: FlipFace;
  flipped: boolean;
  onHover: (v: boolean) => void;
  onToggle: () => void;
}) {
  return (
    <div
      className="cursor-pointer"
      style={{ perspective: '1200px', aspectRatio: '1 / 1' }}
      onMouseEnter={() => onHover(true)}
      onMouseLeave={() => onHover(false)}
      onClick={onToggle}
    >
      <div
        className="relative h-full w-full transition-transform"
        style={{
          transformStyle: 'preserve-3d',
          transform: flipped ? 'rotateY(180deg)' : 'rotateY(0deg)',
          transitionDuration: '700ms',
          transitionTimingFunction: 'cubic-bezier(0.2, 0.6, 0.2, 1)',
        }}
      >
        {/* Front */}
        <div
          className="absolute inset-0 flex flex-col justify-between overflow-hidden rounded-lg p-4.5"
          style={{ background: card.front.bg, color: card.front.fg, backfaceVisibility: 'hidden' }}
        >
          <div>
            <div className="mb-1.5 text-[11px] font-medium uppercase tracking-[0.06em] opacity-70">
              {card.front.field}
            </div>
            <div className="text-[15px] font-medium leading-[1.25] tracking-[-0.01em]">
              {card.front.val}
            </div>
          </div>
          <div className="flex items-end justify-between text-[11px] font-medium opacity-80">
            <span>{card.front.metaLeft}</span>
            <span>{card.front.metaRight}</span>
          </div>
        </div>
        {/* Back */}
        <div
          className="absolute inset-0 flex flex-col justify-between overflow-hidden rounded-lg border-[0.5px] border-ink/14 bg-paper p-4.5"
          style={{ backfaceVisibility: 'hidden', transform: 'rotateY(180deg)' }}
        >
          <div>
            <div className="mb-1.5 text-[11px] font-medium uppercase tracking-[0.06em] text-ink-400">
              {card.back.field}
            </div>
            <div className="text-[15px] font-medium leading-[1.25] tracking-[-0.01em] text-ink">
              {card.back.val}
            </div>
          </div>
          <div className="flex items-end justify-between text-[11px]">
            <span
              className="rounded-full px-2.5 py-0.75 font-medium"
              style={{ background: card.back.flag.bg, color: card.back.flag.fg }}
            >
              {card.back.flag.label}
            </span>
            <span className="font-medium" style={{ color: '#0F9B7E' }}>
              {card.back.ok}
            </span>
          </div>
        </div>
      </div>
    </div>
  );
}

/* ───────────────────────── TERMINAL PREVIEW ───────────────────────── */

function TerminalPreview() {
  return (
    <section className="bg-paper px-10 py-20">
      <div className="mx-auto max-w-[900px]">
        <div className="overflow-hidden rounded-xl border-[0.5px] border-white/6 bg-ink font-mono text-[13px] leading-[1.6] text-mist">
          <div className="flex items-center gap-2 border-b-[0.5px] border-white/8 px-3.5 py-2.5">
            <span className="h-2.5 w-2.5 rounded-full" style={{ background: '#E87E67' }} />
            <span className="h-2.5 w-2.5 rounded-full" style={{ background: '#E9B84A' }} />
            <span className="h-2.5 w-2.5 rounded-full" style={{ background: '#7AB87A' }} />
            <span className="ml-auto text-[11px] text-ink-400">translation · live</span>
          </div>
          <pre className="m-0 whitespace-pre px-6 py-5.5 leading-[1.6]">
            <span className="text-ink-400">$</span> feedgenix translate{' '}
            <span style={{ color: '#6EE7C4' }}>--batch=deindeal.csv</span>
            {'\n'}
            <span className="text-ink-400">→</span> reading 4,832 rows
            {'\n\n'}
            <span className="text-ink-400">field:</span> <span style={{ color: '#F1C292' }}>title_en</span>
            {'\n'}
            <span style={{ color: '#6EE7C4' }}>{'  "Merino wool sweater"'}</span>
            {'\n\n'}
            <span className="text-ink-400">  de</span>  <span style={{ color: '#A5B4FC' }}>"Merinowollpullover"</span>
            {'\n'}
            <span className="text-ink-400">  fr</span>  <span style={{ color: '#A5B4FC' }}>"Pull en laine mérinos"</span>
            {'\n'}
            <span className="text-ink-400">  it</span>  <span style={{ color: '#A5B4FC' }}>"Maglione in lana merino"</span>
            {'\n'}
            <span className="text-ink-400">  el</span>  <span style={{ color: '#A5B4FC' }}>"Πουλόβερ από μαλλί merino"</span>
            {'\n\n'}
            <span style={{ color: '#6EE7C4' }}>✓</span> 4,832 rows · 0.38 ¢/row ·{' '}
            <span style={{ color: '#A5B4FC' }}>cached 61%</span>
          </pre>
        </div>
      </div>
    </section>
  );
}

/* ───────────────────────── FEATURES ───────────────────────── */

function Features() {
  const items = [
    { icon: <TableIcon />,  title: 'Bulk translation at scale', body: 'Up to 10,000 rows per batch. Jobs checkpoint every 1,000 rows so a crashed machine never loses work.' },
    { icon: <ClockIcon />,  title: 'SHA‑256 smart cache',      body: 'Every string is hashed. You pay once per unique phrase, across every feed, forever.' },
    { icon: <ShieldIcon />, title: 'Golden rule protection',   body: 'Prices, stock, barcodes, SKUs and dimensions are rejected by the translator. They pass through untouched.' },
  ];
  return (
    <section id="features" className="bg-sand px-10 py-24">
      <div className="mx-auto grid max-w-280 grid-cols-1 gap-5 md:grid-cols-3">
        {items.map((f) => (
          <div key={f.title} className="rounded-xl border-[0.5px] border-ink/8 bg-paper p-8">
            <div className="mb-4.5 flex h-11 w-11 items-center justify-center rounded-lg bg-indigo-100 text-[#4338CA]">
              {f.icon}
            </div>
            <h3 className="mb-2.5 text-[19px] font-medium tracking-[-0.01em] text-ink">
              {f.title}
            </h3>
            <p className="text-[14px] leading-[1.6] text-ink-500">{f.body}</p>
          </div>
        ))}
      </div>
    </section>
  );
}

/* ───────────────────────── HOW IT WORKS ───────────────────────── */

function HowItWorks() {
  const steps = [
    { n: '01', t: 'Upload CSV',        p: 'Drag-drop any CSV or XML. We parse up to 10K rows in seconds.' },
    { n: '02', t: 'AI field mapping',  p: 'Claude reads your columns and suggests a mapping. You approve or edit.' },
    { n: '03', t: 'Review & approve',  p: 'Preview translations in every target language before you commit.' },
    { n: '04', t: 'Export clean feed', p: 'Download CSV, XLSX, JSON, or push to Shopify via API.' },
  ];
  return (
    <section id="how" className="bg-paper px-10 py-24 fg-hairline-b">
      <div className="mx-auto max-w-280">
        <h2 className="mb-12 text-center text-[40px] font-medium tracking-[-0.02em] text-ink">
          How it works
        </h2>
        <div className="grid grid-cols-1 gap-10 md:grid-cols-[1fr_24px_1fr_24px_1fr_24px_1fr] md:items-start md:gap-0">
          {steps.map((s, i) => (
            <Step key={s.n} step={s} showArrow={i < steps.length - 1} />
          ))}
        </div>
      </div>
    </section>
  );
}

function Step({ step, showArrow }: { step: { n: string; t: string; p: string }; showArrow: boolean }) {
  return (
    <>
      <div>
        <div className="mb-3 font-mono text-[11px] tracking-[0.08em] text-ink-400">
          STEP {step.n}
        </div>
        <div className="text-[19px] font-medium tracking-[-0.01em] text-ink">{step.t}</div>
        <p className="mt-2.5 text-[13.5px] leading-[1.55] text-ink-500">{step.p}</p>
      </div>
      {showArrow && (
        <div className="hidden pt-8 text-center text-[18px] text-ink-300 md:block">→</div>
      )}
    </>
  );
}

/* ───────────────────────── GOLDEN RULE ───────────────────────── */

function GoldenRule() {
  const patterns = [
    'price', 'prix', 'preis', 'prezzo',
    'stock', 'quantity', 'qty', 'bestand',
    'ean', 'upc', 'gtin', 'barcode',
    'sku', 'ref', 'reference', 'article_number',
    'weight', 'gewicht', 'poids', 'dimension',
  ];
  return (
    <section className="bg-paper px-10 py-24">
      <div className="mx-auto max-w-280">
        <div className="grid grid-cols-1 items-start gap-12 md:grid-cols-2">
          <div>
            <span className="fg-eyebrow">THE GOLDEN RULE</span>
            <h2 className="mt-3 text-[40px] font-medium leading-[1.1] tracking-[-0.02em] text-ink">
              Prices, stock and barcodes pass through untouched.
            </h2>
            <p className="mt-5 max-w-130 text-[16px] leading-[1.6] text-ink-500">
              The translator never sees price, stock, SKU, EAN, weight or dimension columns.
              They're detected by pattern, pinned by schema, and streamed straight to the export.
              That's not a feature — it's a promise.
            </p>
          </div>
          <div className="rounded-xl border-[0.5px] border-ink/10 bg-mist p-7">
            <div className="mb-4 text-[12px] font-medium tracking-[0.04em] text-ink-500">
              PROTECTED BY DEFAULT
            </div>
            <ul className="grid grid-cols-2 gap-2">
              {patterns.map((token) => (
                <li key={token} className="flex items-center gap-2 font-mono text-[13px] text-ink-700">
                  <EmeraldCheck /> {token}
                </li>
              ))}
            </ul>
          </div>
        </div>
      </div>
    </section>
  );
}

/* ───────────────────────── PRICING PREVIEW ───────────────────────── */

function PricingPreview() {
  return (
    <section id="pricing" className="bg-mist px-10 py-24">
      <div className="mx-auto max-w-280">
        <h2 className="text-center text-[40px] font-medium tracking-[-0.02em] text-ink">
          Pricing built around rows, not seats.
        </h2>
        <p className="mx-auto mt-2 max-w-xl text-center text-[15px] text-ink-500">
          Every plan includes the Golden Rule, smart cache, and all export formats.
        </p>
        <div className="mt-12 grid grid-cols-1 items-start gap-3.5 md:grid-cols-4">
          <PriceCard name="Free"       price="€0"     per="500 rows / month"        features={['3 target languages', 'CSV export', 'Community support']} cta="Start free" />
          <PriceCard name="Starter"    price="€29"    suffix="/mo" per="10,000 rows / month" features={['10 languages', 'XLSX + JSON export', 'Change detection']} cta="Choose Starter" />
          <PriceCard highlight name="Pro" price="€99" suffix="/mo" per="100,000 rows / month" features={['Unlimited languages', 'API + webhooks', 'Priority routing', '5 team members']} cta="Start Pro trial" />
          <PriceCard name="Enterprise" price="Custom" per="Unlimited rows"          features={['SSO + SOC 2', 'Dedicated success', 'Custom models']} cta="Talk to sales" />
        </div>
        <div className="mt-8 text-center">
          <Link to="/pricing" className="text-[14px] font-medium text-indigo hover:text-indigo-600">
            Compare all features →
          </Link>
        </div>
      </div>
    </section>
  );
}

function PriceCard(props: {
  name: string;
  price: string;
  suffix?: string;
  per: string;
  features: string[];
  cta: string;
  highlight?: boolean;
}) {
  const { name, price, suffix, per, features, cta, highlight } = props;
  return (
    <div
      className={`relative rounded-xl bg-paper ${
        highlight ? 'border-2 border-indigo p-[27px_23px]' : 'border-[0.5px] border-ink/14 p-[28px_24px]'
      }`}
    >
      {highlight && (
        <span className="absolute left-1/2 -top-3.5 -translate-x-1/2 rounded-full bg-sand px-3.5 py-1 text-[11px] font-medium tracking-[0.04em] text-[#A35412]">
          Most popular
        </span>
      )}
      <div className="mb-2.5 text-[12px] font-medium tracking-[0.04em] text-indigo uppercase">{name}</div>
      <div className="text-[40px] font-medium leading-none tracking-[-0.02em] text-ink">
        {price}
        {suffix && <span className="text-[14px] font-normal text-ink-400">{suffix}</span>}
      </div>
      <div className="mt-1.5 min-h-9 text-[13px] text-ink-500">{per}</div>
      <ul className="mt-5 mb-5.5 space-y-1 text-[13.5px]">
        {features.map((f) => (
          <li key={f} className="flex items-start gap-2 py-0.5 text-ink-700">
            <EmeraldCheck className="mt-1 shrink-0" /> {f}
          </li>
        ))}
      </ul>
      <button
        className={`w-full rounded-lg py-2.75 text-[14px] font-medium transition-colors duration-200 ${
          highlight
            ? 'bg-indigo text-white hover:bg-indigo-600'
            : 'border-[0.5px] border-ink/14 bg-paper text-ink hover:bg-mist'
        }`}
      >
        {cta}
      </button>
    </div>
  );
}

/* ───────────────────────── TESTIMONIALS ───────────────────────── */

function Testimonials() {
  return (
    <section className="bg-paper px-10 py-24">
      <div
        className="mx-auto max-w-280 rounded-3xl px-14 py-16"
        style={{ background: '#1FB281', color: '#0A3D2B' }}
      >
        <div className="grid grid-cols-1 items-start gap-12 md:grid-cols-[1.1fr_1.4fr_1.1fr]">
          <Quote
            body={
              <>
                FeedGenix is by far the best feed tool I've come across in a long time.
                <br />
                <br />
                <b className="font-medium">It's an absolute gamechanger</b> for our
                cross-border rollout.
              </>
            }
            attr="Sofia K. · Head of Catalog, Deindeal"
          />

          <div className="flex items-center justify-center">
            <h2 className="relative m-0 text-[72px] font-medium leading-[0.95] tracking-[-0.03em]">
              <Quotemark pos="tl" />
              <span className="block">What</span>
              <span className="block">
                <Box>people</Box>
              </span>
              <span className="block">are</span>
              <span className="block">
                <Box>saying</Box>
              </span>
              <Quotemark pos="br" />
            </h2>
          </div>

          <Quote
            body={
              <>
                I've been doing e-commerce for 15 years and <i>never</i> seen a feed pipeline
                this clean. Not even our internal Laravel one.
              </>
            }
            attr="Lukas T. · CTO, Brack.ch"
          />
        </div>

        <div className="mt-11 grid grid-cols-1 gap-12 md:grid-cols-[1.1fr_1.4fr_1.1fr]">
          <QuoteSmall body="FeedGenix opens up to any merchant the same level of catalog tooling that top marketplaces hire armies of translators and engineers to build internally." source="ACQUIRED PODCAST" />
          <QuoteSmall body="Wow, @feedgenix makes it ridiculously simple to ship a 7-language catalog. Saved us a quarter." source="𝕏 @meetnarenjr" />
          <QuoteSmall body="Most feed tools require you to understand XML schemas and mapping DSLs. The learning curve is brutal. That's not the case at all with FeedGenix." source="MONEY CRASHERS" />
        </div>

        <div className="mt-12 flex flex-col items-center gap-3.5">
          <Link
            to="/register"
            className="inline-flex items-center gap-2 rounded-full bg-ink px-12 py-4 text-[15px] font-medium text-white transition-opacity duration-200 hover:opacity-90"
          >
            Sign up →
          </Link>
          <p className="max-w-[560px] text-center text-[11px]" style={{ color: 'rgba(10,61,43,0.7)' }}>
            Third-party testimonials may not be representative of the experience of all customers
            and are not indicative of future results.
          </p>
        </div>
      </div>
    </section>
  );
}

function Quote({ body, attr }: { body: React.ReactNode; attr: string }) {
  return (
    <div>
      <div className="mb-3 h-px w-4.5" style={{ background: '#F7B8D2' }} />
      <p className="m-0 mb-3.5 text-[15.5px] leading-[1.45]" style={{ color: '#0A3D2B' }}>
        {body}
      </p>
      <div className="mt-2.5 text-[12px] tracking-[0.04em]" style={{ color: 'rgba(10,61,43,0.7)' }}>
        {attr}
      </div>
    </div>
  );
}

function QuoteSmall({ body, source }: { body: string; source: string }) {
  return (
    <div>
      <div className="mb-3 h-px w-4.5" style={{ background: '#F7B8D2' }} />
      <p className="m-0 text-[15.5px] leading-[1.45]" style={{ color: '#0A3D2B' }}>
        {body}
      </p>
      <div className="mt-1.5 text-[11px] font-medium tracking-[0.12em]" style={{ color: 'rgba(10,61,43,0.6)' }}>
        {source}
      </div>
    </div>
  );
}

function Box({ children }: { children: React.ReactNode }) {
  return (
    <span
      className="inline-block rounded-md"
      style={{ background: '#F7B8D2', padding: '0 10px', color: '#6B1E3F' }}
    >
      {children}
    </span>
  );
}

function Quotemark({ pos }: { pos: 'tl' | 'br' }) {
  const positional =
    pos === 'tl'
      ? { top: -10, left: -30 }
      : { bottom: 0, right: -30 };
  return (
    <span
      className="absolute text-[40px] font-medium"
      style={{ color: '#F7B8D2', ...positional }}
    >
      "
    </span>
  );
}

/* ───────────────────────── FAQ ───────────────────────── */

const FAQ_ITEMS = [
  {
    q: 'How do I open a FeedGenix account?',
    a: 'Sign up with your work email, verify with a 6-digit code, then upload your first CSV. The free tier covers 500 rows and 3 languages — no credit card.',
  },
  {
    q: 'What file formats do you support?',
    a: 'CSV (any delimiter), TSV, XML (Google Shopping, Facebook, custom schemas), JSON, and Excel (.xlsx). Up to 10,000 rows per batch on Pro; unlimited on Enterprise.',
  },
  {
    q: 'How does the SHA-256 hash cache work?',
    a: "Every translatable field is hashed before routing. If we've seen the same source + target pair before, we serve it from cache at 0¢. Typical second-run cache hit rates land at 60–75%.",
  },
  {
    q: 'Can I protect prices, stock and barcodes from AI edits?',
    a: "Yes — that's the Golden Rule. Any field tagged as protected is never passed to the model. It's pulled through verbatim, byte-for-byte, into the output feed.",
  },
  {
    q: 'Which AI models do you route across?',
    a: 'Free-tier APIs first (Microsoft Translator, DeepL free), then Claude Opus and Grok for long-tail languages, brand voice, and ambiguous attributes. You can pin any model per language.',
  },
  {
    q: 'Is my catalog data used to train models?',
    a: "No. We use model providers' zero-retention endpoints where available, and we never train on your data. SOC 2 Type II report available on request.",
  },
  {
    q: "Can I use FeedGenix if I'm not based in the EU?",
    a: 'Yes. We serve merchants in 38 countries. EU data stays in Frankfurt; US data in Virginia. Choose your region at workspace setup.',
  },
  {
    q: 'How do I contact support?',
    a: 'Email support@feedgenix.com, or use the in-app chat. Pro plans include a 4-hour response SLA; Enterprise gets a dedicated customer success manager.',
  },
];

function FAQ() {
  // First item open by default, others close-to-open manually. Only one open at a time.
  const [open, setOpen] = useState<number>(0);
  return (
    <section id="faq" className="bg-paper px-10 py-24">
      <div className="mx-auto grid max-w-280 grid-cols-1 gap-15 md:grid-cols-[1fr_1.4fr]">
        <h2 className="text-[56px] font-medium leading-none tracking-[-0.03em] text-ink">
          Frequently
          <br />
          asked
          <br />
          questions
        </h2>

        <div className="border-t-[0.5px] border-ink/10">
          {FAQ_ITEMS.map((item, i) => {
            const isOpen = open === i;
            return (
              <div key={item.q} className="border-b-[0.5px] border-ink/10">
                <button
                  type="button"
                  onClick={() => setOpen(isOpen ? -1 : i)}
                  className="flex w-full items-center justify-between py-4.5 text-left text-[15px] text-ink transition-colors duration-200"
                  aria-expanded={isOpen}
                >
                  <span>{item.q}</span>
                  <span
                    className={`text-ink-300 transition-transform duration-200 ${
                      isOpen ? 'rotate-90 text-indigo' : ''
                    }`}
                    aria-hidden
                  >
                    ›
                  </span>
                </button>
                <div
                  className="overflow-hidden text-[14px] leading-[1.6] text-ink-500 transition-all duration-300"
                  style={{
                    maxHeight: isOpen ? 240 : 0,
                    paddingBottom: isOpen ? 18 : 0,
                  }}
                >
                  {item.a}
                </div>
              </div>
            );
          })}
        </div>
      </div>
    </section>
  );
}

/* ───────────────────────── ICONS ───────────────────────── */

function EmeraldCheck({ className = '' }: { className?: string }) {
  return (
    <svg
      width="14"
      height="14"
      viewBox="0 0 24 24"
      fill="none"
      stroke="#0F9B7E"
      strokeWidth="2.25"
      strokeLinecap="round"
      className={className}
    >
      <polyline points="20 6 9 17 4 12" />
    </svg>
  );
}

function TableIcon() {
  return (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round">
      <path d="M3 5v14h18V5z" />
      <path d="M3 10h18" />
      <path d="M9 5v14" />
    </svg>
  );
}

function ClockIcon() {
  return (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round">
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7v5l3 2" />
    </svg>
  );
}

function ShieldIcon() {
  return (
    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round">
      <path d="M12 2 4 6v6c0 5 3.5 9 8 10 4.5-1 8-5 8-10V6z" />
      <path d="m9 12 2 2 4-4" />
    </svg>
  );
}
