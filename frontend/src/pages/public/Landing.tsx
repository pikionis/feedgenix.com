import { Link } from 'react-router-dom';
import { Button } from '@/components/ui/button';
import { Badge } from '@/components/ui/badge';
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion';

export default function Landing() {
  return (
    <>
      <Hero />
      <FormatSupport />
      <HowItWorks />
      <Features />
      <GoldenRule />
      <Comparison />
      <PricingPreview />
      <FAQ />
      <FinalCTA />
    </>
  );
}

/* ───────────────────────── HERO ───────────────────────── */
function Hero() {
  return (
    <section className="relative overflow-hidden">
      {/* Subtle gradient mesh */}
      <div
        className="pointer-events-none absolute inset-0 -z-10"
        style={{
          background:
            'radial-gradient(ellipse 80% 60% at 50% 0%, hsl(var(--primary) / 0.08), transparent 60%), radial-gradient(ellipse 60% 50% at 80% 20%, hsl(var(--primary) / 0.05), transparent 60%)',
        }}
      />
      <div className="mx-auto max-w-7xl px-6 pb-20 pt-20 sm:pt-28 lg:pt-32">
        <div className="mx-auto max-w-3xl text-center">
          <Badge variant="outline" className="mb-6 rounded-full border-border/60 bg-background/60 px-3 py-1 text-xs font-medium backdrop-blur">
            <span className="mr-2 inline-block h-1.5 w-1.5 rounded-full bg-primary"></span>
            Now supporting 30+ languages
          </Badge>
          <h1 className="font-heading text-5xl font-semibold tracking-tight text-foreground sm:text-6xl md:text-7xl">
            Translate your product catalog
            <br />
            for every EU market —
            <br />
            <span className="relative">
              <span className="relative z-10 bg-linear-to-r from-primary to-[hsl(166_100%_30%)] bg-clip-text text-transparent">
                in minutes, not months.
              </span>
            </span>
          </h1>
          <p className="mx-auto mt-8 max-w-2xl text-lg leading-8 text-muted-foreground">
            Upload CSV, XLSX, or XML. Our AI translates titles, descriptions, and attributes while
            keeping your prices, SKUs, and barcodes untouched. Built for Shopify, WooCommerce,
            Amazon, and any feed-based platform.
          </p>
          <div className="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <Button asChild size="lg" className="h-12 px-6 text-base">
              <Link to="/register">Start free — 100 products</Link>
            </Button>
            <Button asChild size="lg" variant="ghost" className="h-12 px-6 text-base">
              <a href="#how-it-works">See how it works →</a>
            </Button>
          </div>
          <p className="mt-4 text-xs text-muted-foreground">
            No credit card required • Cancel anytime • GDPR compliant
          </p>
        </div>

        {/* Hero mockup */}
        <div className="mx-auto mt-16 max-w-5xl">
          <div className="relative rounded-2xl border border-border/60 bg-card p-1.5 shadow-2xl shadow-primary/5">
            <div className="rounded-xl bg-linear-to-br from-background to-muted/40 p-8">
              <HeroMockup />
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

function HeroMockup() {
  const rows = [
    { src: 'Wireless Bluetooth Headphones', de: 'Kabellose Bluetooth-Kopfhörer', fr: 'Casque Bluetooth sans fil', sku: 'WH-1000', price: '€89.99' },
    { src: 'Smart Home Thermostat', de: 'Smart Home Thermostat', fr: 'Thermostat domotique', sku: 'TH-2200', price: '€149.00' },
    { src: 'Ergonomic Office Chair', de: 'Ergonomischer Bürostuhl', fr: 'Chaise de bureau ergonomique', sku: 'CH-550', price: '€299.00' },
    { src: 'Stainless Steel Water Bottle', de: 'Edelstahl-Trinkflasche', fr: 'Bouteille en acier inoxydable', sku: 'WB-32', price: '€24.50' },
  ];
  return (
    <div className="overflow-hidden rounded-lg border border-border/50 bg-background">
      <div className="border-b border-border/50 bg-muted/40 px-4 py-3">
        <div className="flex items-center gap-2 text-sm text-muted-foreground">
          <span className="h-2.5 w-2.5 rounded-full bg-red-400/70" />
          <span className="h-2.5 w-2.5 rounded-full bg-yellow-400/70" />
          <span className="h-2.5 w-2.5 rounded-full bg-green-400/70" />
          <span className="ml-3 font-mono text-xs">products_catalog.csv → 4 languages</span>
          <span className="ml-auto inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-2 py-0.5 text-xs font-medium text-primary">
            <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-primary" />
            Completed
          </span>
        </div>
      </div>
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="border-b border-border/50 text-xs uppercase tracking-wide text-muted-foreground">
              <th className="px-4 py-3 text-left font-medium">Source (EN)</th>
              <th className="px-4 py-3 text-left font-medium">DE</th>
              <th className="px-4 py-3 text-left font-medium">FR</th>
              <th className="px-4 py-3 text-left font-medium">SKU</th>
              <th className="px-4 py-3 text-right font-medium">Price</th>
            </tr>
          </thead>
          <tbody>
            {rows.map((r, i) => (
              <tr key={i} className="border-b border-border/30 last:border-0">
                <td className="px-4 py-3 font-medium text-foreground">{r.src}</td>
                <td className="px-4 py-3 text-muted-foreground">{r.de}</td>
                <td className="px-4 py-3 text-muted-foreground">{r.fr}</td>
                <td className="px-4 py-3 font-mono text-xs text-foreground">
                  <span className="rounded bg-muted px-1.5 py-0.5">{r.sku}</span>
                </td>
                <td className="px-4 py-3 text-right font-mono text-foreground">{r.price}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="flex items-center justify-between border-t border-border/50 bg-muted/20 px-4 py-2.5 text-xs text-muted-foreground">
        <span>4 of 2,847 products shown</span>
        <span className="inline-flex items-center gap-1.5">
          <span className="h-1.5 w-1.5 rounded-full bg-green-500" />
          SKUs, prices & barcodes protected
        </span>
      </div>
    </div>
  );
}

/* ──────────────────── FORMAT SUPPORT ──────────────────── */
function FormatSupport() {
  const formats = ['CSV', 'XLSX', 'XML', 'JSON', 'TSV'];
  const platforms = ['Shopify', 'WooCommerce', 'Amazon', 'PrestaShop', 'Magento', 'Etsy'];
  return (
    <section id="formats" className="border-y border-border/60 bg-muted/30 py-14">
      <div className="mx-auto max-w-7xl px-6">
        <div className="grid gap-10 md:grid-cols-2">
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Works with every feed format
            </p>
            <div className="mt-4 flex flex-wrap gap-2">
              {formats.map((f) => (
                <span
                  key={f}
                  className="rounded-md border border-border/60 bg-background px-3 py-1.5 font-mono text-sm font-medium text-foreground shadow-sm"
                >
                  .{f.toLowerCase()}
                </span>
              ))}
            </div>
          </div>
          <div>
            <p className="text-xs font-semibold uppercase tracking-wider text-muted-foreground">
              Export-ready for your platform
            </p>
            <div className="mt-4 flex flex-wrap gap-2">
              {platforms.map((p) => (
                <span
                  key={p}
                  className="rounded-md border border-border/60 bg-background px-3 py-1.5 text-sm font-medium text-foreground shadow-sm"
                >
                  {p}
                </span>
              ))}
            </div>
          </div>
        </div>
      </div>
    </section>
  );
}

/* ───────────────────── HOW IT WORKS ───────────────────── */
function HowItWorks() {
  const steps = [
    {
      n: '01',
      title: 'Upload your feed',
      desc: 'Drop in any CSV, XLSX, or XML file. We handle messy data, inconsistent columns, and multi-language sources out of the box.',
    },
    {
      n: '02',
      title: 'AI maps & you review',
      desc: 'Our AI detects your field structure, flags translatable text, and protects prices, SKUs, and barcodes. Approve the map in seconds.',
    },
    {
      n: '03',
      title: 'Translate & download',
      desc: 'Pick your target markets. We translate in parallel using the best provider for each language, cache results, and hand you a clean file ready to import.',
    },
  ];

  return (
    <section id="how-it-works" className="py-24">
      <div className="mx-auto max-w-7xl px-6">
        <div className="mx-auto max-w-2xl text-center">
          <p className="text-xs font-semibold uppercase tracking-wider text-primary">How it works</p>
          <h2 className="mt-3 font-heading text-4xl font-semibold tracking-tight sm:text-5xl">
            From messy feed to<br />market-ready — in 3 steps
          </h2>
          <p className="mt-5 text-lg text-muted-foreground">
            No engineering, no manual column mapping, no broken data.
          </p>
        </div>
        <div className="mt-16 grid gap-8 md:grid-cols-3">
          {steps.map((s) => (
            <div
              key={s.n}
              className="group relative rounded-2xl border border-border/60 bg-card p-8 transition-all hover:border-primary/40 hover:shadow-lg hover:shadow-primary/5"
            >
              <div className="font-mono text-sm font-semibold text-primary">{s.n}</div>
              <h3 className="mt-4 font-heading text-xl font-semibold text-foreground">{s.title}</h3>
              <p className="mt-3 text-sm leading-6 text-muted-foreground">{s.desc}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ───────────────────── FEATURES ───────────────────── */
const featureList = [
  {
    icon: ShieldIcon,
    title: 'Golden Rule Protection',
    desc: 'Prices, stock, SKUs, EAN/UPC barcodes, and dimensions pass through untouched. Built-in safety you can verify.',
  },
  {
    icon: BoltIcon,
    title: 'Smart provider routing',
    desc: 'Microsoft, DeepL, and Claude — we pick the best provider per language and stretch every free tier before using paid ones.',
  },
  {
    icon: LayersIcon,
    title: 'Intelligent cache',
    desc: 'Re-translate 100k products and pay for 2k. SHA-256 content hashing means we never translate the same line twice.',
  },
  {
    icon: GitBranchIcon,
    title: 'Change detection',
    desc: 'Re-upload a feed and only new or changed products are re-translated. Keep costs flat as your catalog grows.',
  },
  {
    icon: GlobeIcon,
    title: '30+ languages',
    desc: 'From EU core (DE, FR, IT, ES, NL, PL) to long-tail (CZ, RO, HU, GR, PT). One feed, every market.',
  },
  {
    icon: CodeIcon,
    title: 'API & webhooks (Pro)',
    desc: 'Automate the whole pipeline from your CMS. REST API, signed webhooks, and scheduled syncs.',
  },
];

function Features() {
  return (
    <section id="features" className="border-t border-border/60 bg-muted/20 py-24">
      <div className="mx-auto max-w-7xl px-6">
        <div className="mx-auto max-w-2xl text-center">
          <p className="text-xs font-semibold uppercase tracking-wider text-primary">Features</p>
          <h2 className="mt-3 font-heading text-4xl font-semibold tracking-tight sm:text-5xl">
            Everything your feed needs —<br />and nothing it doesn't
          </h2>
        </div>
        <div className="mt-16 grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
          {featureList.map((f) => (
            <div
              key={f.title}
              className="rounded-2xl border border-border/60 bg-card p-7 transition-all hover:border-primary/30 hover:shadow-md"
            >
              <div className="inline-flex h-10 w-10 items-center justify-center rounded-lg bg-primary/10 text-primary">
                <f.icon />
              </div>
              <h3 className="mt-5 font-heading text-lg font-semibold">{f.title}</h3>
              <p className="mt-2 text-sm leading-6 text-muted-foreground">{f.desc}</p>
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ───────────────────── GOLDEN RULE ───────────────────── */
function GoldenRule() {
  const safe = ['Prices', 'Stock levels', 'SKUs & references', 'EAN / UPC / GTIN', 'Weights & dimensions', 'URLs & image paths'];
  const translated = ['Product titles', 'Descriptions', 'Bullet points', 'Attribute values', 'Category names', 'Marketing copy'];
  return (
    <section className="py-24">
      <div className="mx-auto max-w-7xl px-6">
        <div className="mx-auto max-w-2xl text-center">
          <p className="text-xs font-semibold uppercase tracking-wider text-primary">The Golden Rule</p>
          <h2 className="mt-3 font-heading text-4xl font-semibold tracking-tight sm:text-5xl">
            We translate what's language.<br />Everything else stays exactly as you sent it.
          </h2>
          <p className="mt-5 text-lg text-muted-foreground">
            The #1 reason bulk-translation tools get fired: they change a price, drop a SKU digit, or
            break a barcode. We don't. Ever.
          </p>
        </div>
        <div className="mx-auto mt-14 grid max-w-4xl gap-6 md:grid-cols-2">
          <div className="rounded-2xl border border-border/60 bg-card p-8">
            <div className="flex items-center gap-2">
              <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-primary/10 text-primary">
                <ShieldIcon />
              </span>
              <h3 className="font-heading text-lg font-semibold">Never touched</h3>
            </div>
            <ul className="mt-5 space-y-2.5 text-sm">
              {safe.map((s) => (
                <li key={s} className="flex items-center gap-2 text-foreground">
                  <CheckIcon className="h-4 w-4 text-primary" /> {s}
                </li>
              ))}
            </ul>
          </div>
          <div className="rounded-2xl border border-border/60 bg-card p-8">
            <div className="flex items-center gap-2">
              <span className="inline-flex h-7 w-7 items-center justify-center rounded-full bg-accent text-accent-foreground">
                <GlobeIcon />
              </span>
              <h3 className="font-heading text-lg font-semibold">Translated</h3>
            </div>
            <ul className="mt-5 space-y-2.5 text-sm">
              {translated.map((s) => (
                <li key={s} className="flex items-center gap-2 text-foreground">
                  <CheckIcon className="h-4 w-4 text-primary" /> {s}
                </li>
              ))}
            </ul>
          </div>
        </div>
      </div>
    </section>
  );
}

/* ───────────────────── COMPARISON ───────────────────── */
function Comparison() {
  const rows = [
    { feature: 'Time to translate 10k products', feedgenix: 'Minutes', translators: '2–6 weeks', gt: '1 hour (manual)', inhouse: '3–6 months' },
    { feature: 'Cost for 10k products / 5 languages', feedgenix: '€79', translators: '€3k–€8k', gt: 'Free (no structure)', inhouse: '€15k+ dev' },
    { feature: 'Protects prices, SKUs, barcodes', feedgenix: true, translators: 'Manual', gt: false, inhouse: 'Depends' },
    { feature: 'Change detection (only retranslate edits)', feedgenix: true, translators: false, gt: false, inhouse: 'Depends' },
    { feature: 'Multi-format (CSV, XLSX, XML)', feedgenix: true, translators: true, gt: false, inhouse: 'Depends' },
    { feature: 'No engineering required', feedgenix: true, translators: true, gt: true, inhouse: false },
  ];

  const cell = (v: unknown) => {
    if (v === true) return <CheckIcon className="mx-auto h-5 w-5 text-primary" />;
    if (v === false) return <XIcon className="mx-auto h-5 w-5 text-muted-foreground/50" />;
    return <span className="text-sm text-muted-foreground">{v as string}</span>;
  };

  return (
    <section className="border-t border-border/60 bg-muted/20 py-24">
      <div className="mx-auto max-w-7xl px-6">
        <div className="mx-auto max-w-2xl text-center">
          <h2 className="font-heading text-4xl font-semibold tracking-tight sm:text-5xl">
            Why teams switch to FeedGenix
          </h2>
        </div>
        <div className="mx-auto mt-14 max-w-5xl overflow-hidden rounded-2xl border border-border/60 bg-card">
          <table className="w-full">
            <thead>
              <tr className="border-b border-border/60">
                <th className="p-5 text-left text-sm font-semibold text-muted-foreground"></th>
                <th className="p-5 text-center text-sm font-semibold text-primary">FeedGenix</th>
                <th className="p-5 text-center text-sm font-semibold text-muted-foreground">
                  Human translators
                </th>
                <th className="p-5 text-center text-sm font-semibold text-muted-foreground">
                  Google Translate
                </th>
                <th className="hidden p-5 text-center text-sm font-semibold text-muted-foreground md:table-cell">
                  In-house build
                </th>
              </tr>
            </thead>
            <tbody>
              {rows.map((r, i) => (
                <tr key={i} className="border-b border-border/40 last:border-0">
                  <td className="p-5 text-sm font-medium text-foreground">{r.feature}</td>
                  <td className="bg-primary/5 p-5 text-center">{cell(r.feedgenix)}</td>
                  <td className="p-5 text-center">{cell(r.translators)}</td>
                  <td className="p-5 text-center">{cell(r.gt)}</td>
                  <td className="hidden p-5 text-center md:table-cell">{cell(r.inhouse)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </section>
  );
}

/* ───────────────────── PRICING PREVIEW ───────────────────── */
function PricingPreview() {
  const tiers = [
    { name: 'Free', price: '€0', period: 'forever', feature: '100 products/mo', highlight: false },
    { name: 'Starter', price: '€29', period: '/mo', feature: '1,000 products/mo', highlight: false },
    { name: 'Pro', price: '€79', period: '/mo', feature: '10,000 products/mo', highlight: true },
    { name: 'Enterprise', price: '€199', period: '/mo', feature: 'Unlimited + API', highlight: false },
  ];

  return (
    <section className="py-24">
      <div className="mx-auto max-w-7xl px-6">
        <div className="mx-auto max-w-2xl text-center">
          <p className="text-xs font-semibold uppercase tracking-wider text-primary">Pricing</p>
          <h2 className="mt-3 font-heading text-4xl font-semibold tracking-tight sm:text-5xl">
            Predictable pricing.<br />Start free, upgrade when you scale.
          </h2>
        </div>
        <div className="mt-14 grid gap-5 md:grid-cols-2 lg:grid-cols-4">
          {tiers.map((t) => (
            <div
              key={t.name}
              className={`relative rounded-2xl border p-6 transition-all ${
                t.highlight
                  ? 'border-primary bg-primary/5 shadow-lg shadow-primary/10'
                  : 'border-border/60 bg-card'
              }`}
            >
              {t.highlight && (
                <span className="absolute -top-3 left-6 rounded-full bg-primary px-3 py-0.5 text-xs font-semibold text-primary-foreground">
                  Most popular
                </span>
              )}
              <h3 className="font-heading text-lg font-semibold">{t.name}</h3>
              <div className="mt-3 flex items-baseline gap-1">
                <span className="font-heading text-4xl font-bold">{t.price}</span>
                <span className="text-sm text-muted-foreground">{t.period}</span>
              </div>
              <p className="mt-2 text-sm text-muted-foreground">{t.feature}</p>
            </div>
          ))}
        </div>
        <div className="mt-10 flex justify-center">
          <Button asChild variant="outline" size="lg">
            <Link to="/pricing">Compare all plans →</Link>
          </Button>
        </div>
      </div>
    </section>
  );
}

/* ───────────────────── FAQ ───────────────────── */
function FAQ() {
  const faqs = [
    {
      q: 'What file formats do you support?',
      a: 'CSV, XLSX, XML, JSON, and TSV. Upload whatever your platform exports — we detect encoding, delimiters, and column structure automatically.',
    },
    {
      q: 'How do you protect my prices and SKUs?',
      a: 'We run a "Golden Rule" check before and after translation. Fields matching price, stock, barcode, SKU, or dimension patterns are flagged as protected and never sent to any translation model. You can also manually mark any field as protected.',
    },
    {
      q: 'Which translation providers do you use?',
      a: 'Microsoft Translator, DeepL, and Anthropic Claude. We route each language to the provider that performs best for it, and we always exhaust free tiers before using paid APIs.',
    },
    {
      q: 'What happens when I re-upload the same feed?',
      a: 'We hash every product row. On re-upload, only new or changed rows get re-translated — everything else comes straight from cache. Your second upload usually costs nothing.',
    },
    {
      q: 'Can I use this with Shopify / WooCommerce / Amazon?',
      a: 'Yes. Export from your platform, translate with us, and import the result. The output file matches your input format exactly — same columns, same order, same encoding. Native plugins are on the roadmap.',
    },
    {
      q: 'Is my data safe?',
      a: 'Your feeds are stored encrypted, scoped to your team, and deleted on request. We do not share or train on your data. GDPR-compliant, EU-hosted.',
    },
    {
      q: 'Do I need a credit card to try it?',
      a: 'No. The Free plan gives you 100 products/month forever. Upgrade only when you need more.',
    },
  ];

  return (
    <section id="faq" className="border-t border-border/60 bg-muted/20 py-24">
      <div className="mx-auto max-w-3xl px-6">
        <div className="text-center">
          <p className="text-xs font-semibold uppercase tracking-wider text-primary">FAQ</p>
          <h2 className="mt-3 font-heading text-4xl font-semibold tracking-tight sm:text-5xl">
            Questions, answered
          </h2>
        </div>
        <Accordion type="single" collapsible className="mt-12 w-full">
          {faqs.map((f, i) => (
            <AccordionItem key={i} value={`item-${i}`} className="border-border/60">
              <AccordionTrigger className="text-left font-medium text-foreground hover:text-primary">
                {f.q}
              </AccordionTrigger>
              <AccordionContent className="text-muted-foreground">{f.a}</AccordionContent>
            </AccordionItem>
          ))}
        </Accordion>
      </div>
    </section>
  );
}

/* ───────────────────── FINAL CTA ───────────────────── */
function FinalCTA() {
  return (
    <section className="py-24">
      <div className="mx-auto max-w-5xl px-6">
        <div className="relative overflow-hidden rounded-3xl border border-border/60 bg-linear-to-br from-primary/10 via-background to-background p-12 text-center md:p-16">
          <div
            className="pointer-events-none absolute inset-0 -z-10"
            style={{
              background:
                'radial-gradient(ellipse 60% 60% at 50% 0%, hsl(var(--primary) / 0.15), transparent 70%)',
            }}
          />
          <h2 className="font-heading text-4xl font-semibold tracking-tight sm:text-5xl">
            Stop copy-pasting into Google Translate.
          </h2>
          <p className="mx-auto mt-5 max-w-xl text-lg text-muted-foreground">
            Join sellers shipping across 30+ EU markets without breaking their catalog.
          </p>
          <div className="mt-10 flex flex-col items-center justify-center gap-3 sm:flex-row">
            <Button asChild size="lg" className="h-12 px-6 text-base">
              <Link to="/register">Start free</Link>
            </Button>
            <Button asChild size="lg" variant="outline" className="h-12 px-6 text-base">
              <Link to="/pricing">See pricing</Link>
            </Button>
          </div>
          <p className="mt-4 text-xs text-muted-foreground">
            100 products on the house • No credit card • 5-minute setup
          </p>
        </div>
      </div>
    </section>
  );
}

/* ───────────────────── ICONS ───────────────────── */
function IconBase({ children }: { children: React.ReactNode }) {
  return (
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" className="h-5 w-5">
      {children}
    </svg>
  );
}
function ShieldIcon() { return <IconBase><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z" /></IconBase>; }
function BoltIcon() { return <IconBase><path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" /></IconBase>; }
function LayersIcon() { return <IconBase><path d="M12 2L2 7l10 5 10-5-10-5z" /><path d="M2 17l10 5 10-5" /><path d="M2 12l10 5 10-5" /></IconBase>; }
function GitBranchIcon() { return <IconBase><line x1="6" y1="3" x2="6" y2="15" /><circle cx="18" cy="6" r="3" /><circle cx="6" cy="18" r="3" /><path d="M18 9a9 9 0 0 1-9 9" /></IconBase>; }
function GlobeIcon() { return <IconBase><circle cx="12" cy="12" r="10" /><line x1="2" y1="12" x2="22" y2="12" /><path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z" /></IconBase>; }
function CodeIcon() { return <IconBase><polyline points="16 18 22 12 16 6" /><polyline points="8 6 2 12 8 18" /></IconBase>; }
function CheckIcon({ className = 'h-4 w-4' }: { className?: string }) {
  return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round"><polyline points="20 6 9 17 4 12" /></svg>;
}
function XIcon({ className = 'h-4 w-4' }: { className?: string }) {
  return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><line x1="18" y1="6" x2="6" y2="18" /><line x1="6" y1="6" x2="18" y2="18" /></svg>;
}
