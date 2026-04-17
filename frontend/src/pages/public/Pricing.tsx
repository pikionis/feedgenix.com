import { Link } from 'react-router-dom';
import { useState } from 'react';
import { Button } from '@/components/ui/button';

type BillingCycle = 'monthly' | 'yearly';

const tiers = [
  {
    name: 'Free',
    tagline: 'Try FeedGenix risk-free.',
    monthly: 0,
    yearly: 0,
    limit: '100 products/mo',
    features: [
      '1 target language',
      '1 team member',
      'CSV, XLSX, XML upload',
      'Basic field mapping',
      'CSV export',
    ],
    cta: 'Start free',
    ctaVariant: 'outline' as const,
    highlighted: false,
  },
  {
    name: 'Starter',
    tagline: 'For small sellers going cross-border.',
    monthly: 29,
    yearly: 232,
    limit: '1,000 products/mo',
    features: [
      '3 target languages',
      '2 team members',
      'Smart AI field mapping',
      'CSV + XLSX export',
      'Change detection',
      'Translation cache',
      'Email support',
    ],
    cta: 'Start free trial',
    ctaVariant: 'default' as const,
    highlighted: false,
  },
  {
    name: 'Pro',
    tagline: 'For growing catalogs across many markets.',
    monthly: 79,
    yearly: 632,
    limit: '10,000 products/mo',
    features: [
      '10 target languages',
      '5 team members',
      'CSV, XLSX, XML, JSON export',
      'Continuous sync',
      'API access + webhooks',
      'Golden Rule editor',
      'Priority email support',
    ],
    cta: 'Start free trial',
    ctaVariant: 'default' as const,
    highlighted: true,
  },
  {
    name: 'Enterprise',
    tagline: 'Unlimited scale, white-glove onboarding.',
    monthly: 199,
    yearly: 1592,
    limit: 'Unlimited products',
    features: [
      'Unlimited languages',
      'Unlimited team members',
      'All features included',
      'SSO / SAML',
      'Dedicated CSM',
      'Custom integrations',
      'SLA + 99.9% uptime',
    ],
    cta: 'Contact sales',
    ctaVariant: 'outline' as const,
    highlighted: false,
  },
];

const comparisonGroups = [
  {
    title: 'Catalog',
    rows: [
      { label: 'Products per month', values: ['100', '1,000', '10,000', 'Unlimited'] },
      { label: 'Target languages', values: ['1', '3', '10', 'Unlimited'] },
      { label: 'Team members', values: ['1', '2', '5', 'Unlimited'] },
    ],
  },
  {
    title: 'Formats',
    rows: [
      { label: 'CSV upload & export', values: [true, true, true, true] },
      { label: 'XLSX export', values: [false, true, true, true] },
      { label: 'XML export', values: [false, false, true, true] },
      { label: 'JSON export', values: [false, false, true, true] },
    ],
  },
  {
    title: 'AI & translation',
    rows: [
      { label: 'Smart field mapping', values: [false, true, true, true] },
      { label: 'Translation cache (SHA-256)', values: [true, true, true, true] },
      { label: 'Change detection', values: [false, true, true, true] },
      { label: 'Continuous sync', values: [false, false, true, true] },
      { label: 'Golden Rule editor', values: [false, false, true, true] },
    ],
  },
  {
    title: 'Developer',
    rows: [
      { label: 'REST API', values: [false, false, true, true] },
      { label: 'Webhooks', values: [false, false, true, true] },
      { label: 'Custom integrations', values: [false, false, false, true] },
    ],
  },
  {
    title: 'Support',
    rows: [
      { label: 'Community', values: [true, true, true, true] },
      { label: 'Email support', values: [false, true, true, true] },
      { label: 'Priority support', values: [false, false, true, true] },
      { label: 'Dedicated CSM', values: [false, false, false, true] },
      { label: 'SLA', values: [false, false, false, true] },
    ],
  },
];

export default function Pricing() {
  const [cycle, setCycle] = useState<BillingCycle>('monthly');

  return (
    <>
      {/* Hero */}
      <section className="relative">
        <div
          className="pointer-events-none absolute inset-0 -z-10"
          style={{
            background:
              'radial-gradient(ellipse 70% 50% at 50% 0%, hsl(var(--primary) / 0.08), transparent 60%)',
          }}
        />
        <div className="mx-auto max-w-7xl px-6 pb-16 pt-20 sm:pt-24">
          <div className="mx-auto max-w-2xl text-center">
            <p className="text-xs font-semibold uppercase tracking-wider text-primary">Pricing</p>
            <h1 className="mt-3 font-heading text-5xl font-semibold tracking-tight sm:text-6xl">
              Simple, predictable pricing.
            </h1>
            <p className="mt-5 text-lg text-muted-foreground">
              Pay for products translated, not seats or features. Free forever for the first 100
              products every month.
            </p>
          </div>

          {/* Cycle toggle */}
          <div className="mt-10 flex justify-center">
            <div className="inline-flex items-center rounded-full border border-border/60 bg-muted/30 p-1">
              <button
                onClick={() => setCycle('monthly')}
                className={`rounded-full px-5 py-1.5 text-sm font-medium transition-all ${
                  cycle === 'monthly'
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground'
                }`}
              >
                Monthly
              </button>
              <button
                onClick={() => setCycle('yearly')}
                className={`rounded-full px-5 py-1.5 text-sm font-medium transition-all ${
                  cycle === 'yearly'
                    ? 'bg-background text-foreground shadow-sm'
                    : 'text-muted-foreground'
                }`}
              >
                Yearly
                <span className="ml-2 rounded-full bg-primary/15 px-2 py-0.5 text-xs font-semibold text-primary">
                  −20%
                </span>
              </button>
            </div>
          </div>

          {/* Tier cards */}
          <div className="mt-12 grid gap-6 md:grid-cols-2 lg:grid-cols-4">
            {tiers.map((tier) => {
              const price = cycle === 'monthly' ? tier.monthly : Math.round(tier.yearly / 12);
              return (
                <div
                  key={tier.name}
                  className={`relative flex flex-col rounded-2xl border p-7 transition-all ${
                    tier.highlighted
                      ? 'border-primary/60 bg-linear-to-br from-primary/5 to-transparent shadow-xl shadow-primary/10'
                      : 'border-border/60 bg-card hover:border-border'
                  }`}
                >
                  {tier.highlighted && (
                    <span className="absolute -top-3 left-7 rounded-full bg-primary px-3 py-1 text-xs font-semibold text-primary-foreground shadow-sm">
                      Most popular
                    </span>
                  )}
                  <h3 className="font-heading text-xl font-semibold">{tier.name}</h3>
                  <p className="mt-1 text-sm text-muted-foreground">{tier.tagline}</p>
                  <div className="mt-6 flex items-baseline gap-1.5">
                    <span className="font-heading text-5xl font-bold tracking-tight">
                      €{price}
                    </span>
                    <span className="text-sm text-muted-foreground">
                      {price === 0 ? 'forever' : '/month'}
                    </span>
                  </div>
                  {cycle === 'yearly' && tier.yearly > 0 && (
                    <p className="mt-1 text-xs text-muted-foreground">
                      Billed €{tier.yearly} yearly
                    </p>
                  )}
                  <p className="mt-4 text-sm font-medium text-foreground">{tier.limit}</p>
                  <ul className="mt-6 flex-1 space-y-2.5">
                    {tier.features.map((f) => (
                      <li key={f} className="flex items-start gap-2 text-sm text-foreground">
                        <CheckIcon className="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                        {f}
                      </li>
                    ))}
                  </ul>
                  <Button
                    asChild
                    variant={tier.ctaVariant}
                    className="mt-8 w-full"
                    size="lg"
                  >
                    <Link to={tier.name === 'Enterprise' ? 'mailto:hello@feedgenix.com' : '/register'}>
                      {tier.cta}
                    </Link>
                  </Button>
                </div>
              );
            })}
          </div>

          <p className="mt-8 text-center text-sm text-muted-foreground">
            All plans include: Golden Rule protection • SHA-256 cached translations • GDPR
            compliance • EU hosting
          </p>
        </div>
      </section>

      {/* Comparison table */}
      <section className="border-t border-border/60 bg-muted/20 py-20">
        <div className="mx-auto max-w-7xl px-6">
          <div className="mx-auto max-w-2xl text-center">
            <h2 className="font-heading text-3xl font-semibold tracking-tight sm:text-4xl">
              Compare plans in detail
            </h2>
          </div>
          <div className="mt-12 overflow-hidden rounded-2xl border border-border/60 bg-card">
            {/* Sticky header */}
            <div className="hidden grid-cols-5 border-b border-border/60 bg-muted/40 md:grid">
              <div className="p-5 text-sm font-semibold text-muted-foreground">Features</div>
              {tiers.map((t) => (
                <div
                  key={t.name}
                  className={`p-5 text-center ${t.highlighted ? 'bg-primary/5' : ''}`}
                >
                  <div className="font-heading text-base font-semibold">{t.name}</div>
                  <div className="mt-0.5 text-xs text-muted-foreground">
                    €{cycle === 'monthly' ? t.monthly : Math.round(t.yearly / 12)}
                    {t.monthly > 0 && '/mo'}
                  </div>
                </div>
              ))}
            </div>
            {/* Groups */}
            {comparisonGroups.map((g) => (
              <div key={g.title}>
                <div className="border-b border-border/40 bg-muted/20 px-5 py-3 text-xs font-semibold uppercase tracking-wider text-muted-foreground md:col-span-5">
                  {g.title}
                </div>
                {g.rows.map((r, i) => (
                  <div
                    key={i}
                    className="grid grid-cols-2 border-b border-border/30 last:border-0 md:grid-cols-5"
                  >
                    <div className="p-5 text-sm text-foreground md:col-span-1">{r.label}</div>
                    {r.values.map((v, j) => (
                      <div
                        key={j}
                        className={`flex items-center justify-center p-5 ${
                          tiers[j].highlighted ? 'bg-primary/5' : ''
                        }`}
                      >
                        {typeof v === 'boolean' ? (
                          v ? (
                            <CheckIcon className="h-5 w-5 text-primary" />
                          ) : (
                            <XIcon className="h-5 w-5 text-muted-foreground/40" />
                          )
                        ) : (
                          <span className="text-sm font-medium text-foreground">{v}</span>
                        )}
                      </div>
                    ))}
                  </div>
                ))}
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* FAQ lite */}
      <section className="py-20">
        <div className="mx-auto max-w-3xl px-6">
          <h2 className="text-center font-heading text-3xl font-semibold tracking-tight">
            Pricing questions
          </h2>
          <div className="mt-10 space-y-8">
            <FAQItem
              q="How is a 'product' counted?"
              a="One product = one row in your feed, regardless of how many languages you translate it into. Translating a 1,000-product feed into 5 languages counts as 1,000 — not 5,000."
            />
            <FAQItem
              q="What happens if I exceed my monthly limit?"
              a="You can keep using the app, but new translations pause until the next cycle or an upgrade. We'll email you at 80% usage so there are no surprises."
            />
            <FAQItem
              q="Can I cancel or downgrade anytime?"
              a="Yes. Cancel in one click — you keep access until the end of your billing period. Downgrades take effect at the next cycle."
            />
            <FAQItem
              q="Do unused products roll over?"
              a="No, the quota resets each month. But our translation cache means re-translating the same products next month is effectively free."
            />
            <FAQItem
              q="Do you offer discounts for NGOs / education?"
              a="Yes — 50% off any paid plan. Drop us a line at hello@feedgenix.com with proof of status."
            />
          </div>
        </div>
      </section>
    </>
  );
}

function FAQItem({ q, a }: { q: string; a: string }) {
  return (
    <div className="border-b border-border/60 pb-6">
      <h3 className="font-medium text-foreground">{q}</h3>
      <p className="mt-2 text-sm leading-6 text-muted-foreground">{a}</p>
    </div>
  );
}

function CheckIcon({ className = 'h-4 w-4' }: { className?: string }) {
  return (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round">
      <polyline points="20 6 9 17 4 12" />
    </svg>
  );
}
function XIcon({ className = 'h-4 w-4' }: { className?: string }) {
  return (
    <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
      <line x1="18" y1="6" x2="6" y2="18" />
      <line x1="6" y1="6" x2="18" y2="18" />
    </svg>
  );
}
