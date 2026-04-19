import { Link } from 'react-router-dom';
import {
  Accordion,
  AccordionContent,
  AccordionItem,
  AccordionTrigger,
} from '@/components/ui/accordion';

/**
 * Marketing landing page — recomposed against the FeedGenix design system.
 * Flat, hairline-bordered, no gradients, no shadows. Sand and Mist surfaces
 * carry the section rhythm instead of imagery.
 */

export default function Landing() {
  return (
    <>
      <Hero />
      <Features />
      <HowItWorks />
      <GoldenRule />
      <PricingPreview />
      <FAQ />
      <FinalCTA />
    </>
  );
}

/* ───────────────────────── HERO ───────────────────────── */

function Hero() {
  return (
    <section className="bg-paper">
      <div className="mx-auto grid max-w-300 grid-cols-1 items-center gap-15 px-10 py-20 lg:grid-cols-2">
        <div>
          <span className="inline-flex items-center gap-1.5 rounded-full bg-indigo-100 px-3 py-1.5 text-[12px] font-medium tracking-[0.03em] text-[#4338CA]">
            ✦ AI-native feed platform
          </span>
          <h1 className="mt-5.5 text-[56px] font-medium leading-[1.05] tracking-[-0.022em] text-ink">
            Ship your catalog in every language your buyers shop in.
          </h1>
          <p className="mt-4.5 max-w-130 text-[17px] leading-[1.55] text-ink-500">
            Upload a CSV, let FeedGenix translate 10,000 products into your target markets,
            and ship a clean feed — with hash-cached pricing that only bills once per unique string.
          </p>
          <div className="mt-8 flex flex-wrap gap-2.5">
            <Link
              to="/register"
              className="rounded-lg bg-indigo px-4 py-2.25 text-[14px] font-medium text-white transition-colors duration-200 hover:bg-indigo-600"
            >
              Start free — 500 rows
            </Link>
            <a
              href="#how"
              className="rounded-lg border-[0.5px] border-ink/14 bg-paper px-4 py-2.25 text-[14px] font-medium text-ink transition-colors duration-200 hover:bg-mist"
            >
              Watch 2-min demo
            </a>
          </div>
          <div className="mt-6 flex flex-wrap items-center gap-5 text-[13px] text-ink-400">
            <span className="inline-flex items-center gap-1.5">
              <span className="text-[#C87A1B]">★</span> 4.8 on G2
            </span>
            <span className="inline-flex items-center gap-1.5 text-[#0F9B7E]">
              <ShieldCheckIcon /> <span className="text-ink-400">SOC 2 type II</span>
            </span>
            <span>No credit card</span>
          </div>
        </div>

        <TerminalPreview />
      </div>
    </section>
  );
}

function TerminalPreview() {
  return (
    <div className="overflow-hidden rounded-xl border-[0.5px] border-white/6 bg-ink font-mono text-[13px] leading-[1.6] text-mist">
      <div className="flex items-center gap-2 border-b-[0.5px] border-white/8 px-3.5 py-2.5">
        <span className="h-2.5 w-2.5 rounded-full" style={{ background: '#E87E67' }} />
        <span className="h-2.5 w-2.5 rounded-full" style={{ background: '#E9B84A' }} />
        <span className="h-2.5 w-2.5 rounded-full" style={{ background: '#7AB87A' }} />
        <span className="ml-auto text-[11px] text-ink-400">translation · live</span>
      </div>
      <pre className="m-0 whitespace-pre p-5 leading-[1.6]">
        <span className="text-ink-400">$</span> feedgenix translate{' '}
        <span className="text-[#6EE7C4]">--batch=deindeal.csv</span>
        {'\n'}
        <span className="text-ink-400">→</span> reading 4,832 rows
        {'\n\n'}
        <span className="text-ink-400">field:</span> <span className="text-[#F1C292]">title_en</span>
        {'\n'}
        <span className="text-[#6EE7C4]">{'  "Merino wool sweater"'}</span>
        {'\n\n'}
        <span className="text-ink-400">  de</span>  <span className="text-[#A5B4FC]">"Merinowollpullover"</span>
        {'\n'}
        <span className="text-ink-400">  fr</span>  <span className="text-[#A5B4FC]">"Pull en laine mérinos"</span>
        {'\n'}
        <span className="text-ink-400">  it</span>  <span className="text-[#A5B4FC]">"Maglione in lana merino"</span>
        {'\n'}
        <span className="text-ink-400">  el</span>  <span className="text-[#A5B4FC]">"Πουλόβερ από μαλλί merino"</span>
        {'\n\n'}
        <span className="text-[#6EE7C4]">✓</span> 4,832 rows · 0.38 ¢/row ·{' '}
        <span className="text-[#A5B4FC]">cached 61%</span>
      </pre>
    </div>
  );
}

/* ───────────────────────── FEATURES ───────────────────────── */

function Features() {
  const items = [
    {
      icon: <TableIcon />,
      title: 'Bulk translation at scale',
      body: 'Up to 10,000 rows per batch. Jobs checkpoint every 1,000 rows so a crashed machine never loses work.',
    },
    {
      icon: <ClockIcon />,
      title: 'SHA‑256 smart cache',
      body: 'Every string is hashed. You pay once per unique phrase, across every feed, forever.',
    },
    {
      icon: <ShieldIcon />,
      title: 'Golden rule protection',
      body: 'Prices, stock, barcodes, SKUs and dimensions are rejected by the translator. They pass through untouched.',
    },
  ];
  return (
    <section id="features" className="bg-sand">
      <div className="mx-auto max-w-280 px-10 py-20">
        <div className="grid grid-cols-1 gap-5 md:grid-cols-3">
          {items.map((f) => (
            <div key={f.title} className="rounded-xl border-[0.5px] border-ink/8 bg-paper p-7">
              <div className="mb-4 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100 text-[#4338CA]">
                {f.icon}
              </div>
              <h3 className="mb-2 text-[18px] font-medium tracking-[-0.01em] text-ink">
                {f.title}
              </h3>
              <p className="text-[14px] leading-[1.6] text-ink-500">{f.body}</p>
            </div>
          ))}
        </div>
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
    <section id="how" className="bg-paper fg-hairline-b">
      <div className="mx-auto max-w-300 px-10 py-20">
        <h2 className="mb-10 text-[32px] font-medium tracking-[-0.02em] text-ink">
          How it works
        </h2>
        <div className="grid grid-cols-1 gap-10 md:grid-cols-[1fr_24px_1fr_24px_1fr_24px_1fr] md:items-center md:gap-0">
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
        <div className="mb-2.5 font-mono text-[12px] tracking-[0.08em] text-ink-400">
          STEP {step.n}
        </div>
        <div className="text-[18px] font-medium tracking-[-0.01em] text-ink">{step.t}</div>
        <p className="mt-2 text-[13px] leading-[1.55] text-ink-500">{step.p}</p>
      </div>
      {showArrow && <div className="hidden text-center text-ink-300 md:block">→</div>}
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
    <section className="bg-paper">
      <div className="mx-auto max-w-300 px-10 py-20">
        <div className="grid grid-cols-1 items-start gap-12 md:grid-cols-2">
          <div>
            <span className="fg-eyebrow">THE GOLDEN RULE</span>
            <h2 className="mt-3 text-[36px] font-medium leading-[1.1] tracking-[-0.02em] text-ink">
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
    <section id="pricing" className="bg-paper fg-hairline-t">
      <div className="mx-auto max-w-300 px-10 py-20">
        <h2 className="text-center text-[36px] font-medium tracking-[-0.02em] text-ink">
          Pricing built around rows, not seats.
        </h2>
        <p className="mx-auto mt-1.5 max-w-xl text-center text-[15px] text-ink-500">
          Every plan includes the Golden Rule, smart cache, and all export formats.
        </p>
        <div className="mt-11 grid grid-cols-1 items-start gap-3.5 md:grid-cols-4">
          <PriceCard
            name="Free"
            price="€0"
            per="500 rows / month"
            features={['3 target languages', 'CSV export', 'Community support']}
            cta="Start free"
          />
          <PriceCard
            name="Starter"
            price="€29"
            priceSuffix="/mo"
            per="10,000 rows / month"
            features={['10 languages', 'XLSX + JSON export', 'Change detection']}
            cta="Choose Starter"
          />
          <PriceCard
            highlight
            name="Pro"
            price="€99"
            priceSuffix="/mo"
            per="100,000 rows / month"
            features={['Unlimited languages', 'API + webhooks', 'Priority routing', '5 team members']}
            cta="Start Pro trial"
          />
          <PriceCard
            name="Enterprise"
            price="Custom"
            per="Unlimited rows"
            features={['SSO + SOC 2', 'Dedicated success', 'Custom models']}
            cta="Talk to sales"
          />
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
  priceSuffix?: string;
  per: string;
  features: string[];
  cta: string;
  highlight?: boolean;
}) {
  const { name, price, priceSuffix, per, features, cta, highlight } = props;
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
      <div className="mb-2.5 text-[12px] font-medium tracking-[0.04em] text-indigo">{name}</div>
      <div className="text-[40px] font-medium leading-none tracking-[-0.02em] text-ink">
        {price}
        {priceSuffix && (
          <span className="text-[14px] font-normal text-ink-400">{priceSuffix}</span>
        )}
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
        className={`w-full rounded-lg py-2.5 text-[14px] font-medium transition-colors duration-200 ${
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

/* ───────────────────────── FAQ ───────────────────────── */

function FAQ() {
  const faqs = [
    {
      q: 'How does the smart cache save me money?',
      a: "Every source phrase is hashed with SHA-256 against its target language. If the same phrase has been translated before — even for a different feed or customer — we reuse the stored translation instead of paying a provider. Merchants with recurring catalogs see cache hit rates of 60–80%.",
    },
    {
      q: 'Which languages are supported?',
      a: 'All 30+ languages covered by our provider chain: Microsoft Translator, DeepL, Claude Opus, and Grok. European markets (DE, FR, IT, EL, ES, NL, PT) are fully tested end-to-end.',
    },
    {
      q: 'What file formats can I upload?',
      a: 'CSV and XML on input. On export: CSV, XLSX, JSON, and direct push to Shopify via API on the Pro plan.',
    },
    {
      q: 'What happens if a translation provider fails?',
      a: 'The router tries free providers first, then paid fallbacks. A circuit breaker cools down any provider after 5 consecutive failures. If the monthly spend cap is reached, we fail fast rather than silently overspend.',
    },
    {
      q: 'Can I review translations before export?',
      a: 'Yes. Every field mapping is paused for review — you approve or edit the mapping, then preview translations in each target language before committing to the full batch.',
    },
  ];
  return (
    <section id="faq" className="bg-mist">
      <div className="mx-auto max-w-200 px-10 py-20">
        <h2 className="mb-10 text-center text-[36px] font-medium tracking-[-0.02em] text-ink">
          Frequently asked questions
        </h2>
        <Accordion type="single" collapsible className="divide-y-[0.5px] divide-ink/10">
          {faqs.map((f, i) => (
            <AccordionItem key={f.q} value={`q-${i}`} className="border-0 py-1">
              <AccordionTrigger className="py-5 text-left text-[16px] font-medium text-ink hover:no-underline">
                {f.q}
              </AccordionTrigger>
              <AccordionContent className="pb-5 pr-10 text-[15px] leading-[1.6] text-ink-500">
                {f.a}
              </AccordionContent>
            </AccordionItem>
          ))}
        </Accordion>
      </div>
    </section>
  );
}

/* ───────────────────────── FINAL CTA ───────────────────────── */

function FinalCTA() {
  return (
    <section className="bg-paper">
      <div className="mx-auto max-w-240 px-10 py-24 text-center">
        <h2 className="text-[40px] font-medium leading-[1.1] tracking-[-0.02em] text-ink">
          Start translating your catalog today.
        </h2>
        <p className="mx-auto mt-4 max-w-xl text-[16px] leading-[1.6] text-ink-500">
          500 rows free — no credit card. Translate your first CSV in under 4 minutes.
        </p>
        <div className="mt-7 flex flex-wrap justify-center gap-2.5">
          <Link
            to="/register"
            className="rounded-lg bg-indigo px-5 py-2.75 text-[14px] font-medium text-white transition-colors duration-200 hover:bg-indigo-600"
          >
            Start free
          </Link>
          <Link
            to="/pricing"
            className="rounded-lg border-[0.5px] border-ink/14 bg-paper px-5 py-2.75 text-[14px] font-medium text-ink transition-colors duration-200 hover:bg-mist"
          >
            See pricing
          </Link>
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

function ShieldCheckIcon() {
  return (
    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round">
      <path d="m9 12 2 2 4-4" />
      <path d="M12 2 4 6v6c0 5 3.5 9 8 10 4.5-1 8-5 8-10V6z" />
    </svg>
  );
}

function TableIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round">
      <path d="M3 5v14h18V5z" />
      <path d="M3 10h18" />
      <path d="M9 5v14" />
    </svg>
  );
}

function ClockIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round">
      <circle cx="12" cy="12" r="9" />
      <path d="M12 7v5l3 2" />
    </svg>
  );
}

function ShieldIcon() {
  return (
    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round">
      <path d="M12 2 4 6v6c0 5 3.5 9 8 10 4.5-1 8-5 8-10V6z" />
      <path d="m9 12 2 2 4-4" />
    </svg>
  );
}
