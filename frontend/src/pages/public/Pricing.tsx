import { Link, useNavigate } from 'react-router-dom';
import { useState } from 'react';
import { useAuthStore } from '@/stores/authStore';
import { useSubscriptionStore, type Plan } from '@/stores/subscriptionStore';

type BillingCycle = 'monthly' | 'yearly';

/** Tier name → internal plan id. */
const TIER_TO_PLAN: Record<string, Plan> = {
  Free: 'free',
  Starter: 'starter',
  Pro: 'pro',
  Enterprise: 'enterprise',
};

/**
 * Dedicated pricing page — same 4-tier layout as the landing preview,
 * but with a yearly toggle and an honest feature comparison table below.
 */

type Tier = {
  name: string;
  eyebrow: string;
  monthly: number | 'custom';
  yearly: number | 'custom';
  rowsPerMonth: string;
  features: string[];
  cta: string;
  highlight?: boolean;
};

const tiers: Tier[] = [
  {
    name: 'Free',
    eyebrow: 'Try it out',
    monthly: 0,
    yearly: 0,
    rowsPerMonth: '500 rows / month',
    features: [
      '3 target languages',
      'CSV upload & export',
      'Community support',
      'Basic field mapping',
    ],
    cta: 'Start free',
  },
  {
    name: 'Starter',
    eyebrow: 'For small merchants',
    monthly: 29,
    yearly: 232,
    rowsPerMonth: '10,000 rows / month',
    features: [
      '10 target languages',
      'XLSX + JSON export',
      'Change detection',
      'Email support',
    ],
    cta: 'Choose Starter',
  },
  {
    name: 'Pro',
    eyebrow: 'Most popular',
    monthly: 99,
    yearly: 792,
    rowsPerMonth: '100,000 rows / month',
    features: [
      'Unlimited languages',
      'API + webhooks',
      'Priority provider routing',
      '5 team members',
      'Shopify push',
    ],
    cta: 'Start Pro trial',
    highlight: true,
  },
  {
    name: 'Enterprise',
    eyebrow: 'Custom',
    monthly: 'custom',
    yearly: 'custom',
    rowsPerMonth: 'Unlimited rows',
    features: [
      'SSO + SCIM',
      'SOC 2 Type II, DPA',
      'Dedicated success manager',
      'Custom models / self-hosted providers',
    ],
    cta: 'Talk to sales',
  },
];

export default function Pricing() {
  const [cycle, setCycle] = useState<BillingCycle>('monthly');
  const checkoutError = useSubscriptionStore((s) => s.error);

  return (
    <>
      <section className="bg-paper">
        <div className="mx-auto max-w-300 px-10 pt-20 pb-6 text-center">
          <span className="fg-eyebrow">PRICING</span>
          <h1 className="mx-auto mt-3 max-w-4xl text-[48px] font-medium leading-[1.05] tracking-[-0.02em] text-ink">
            Pricing built around rows, not seats.
          </h1>
          <p className="mx-auto mt-4 max-w-xl text-[16px] leading-[1.6] text-ink-500">
            Every plan includes the Golden Rule, hash-cached translations, and all export
            formats. Scale rows, not headcount.
          </p>
          <CycleToggle value={cycle} onChange={setCycle} />
        </div>

        <div className="mx-auto max-w-300 px-10 pb-20">
          {checkoutError && (
            <div
              role="alert"
              className="mb-4 rounded-lg border-[0.5px] border-[#C6392A]/30 bg-[#FCE5E1] px-3 py-2.5 text-[13px] text-[#C6392A]"
            >
              {checkoutError}
            </div>
          )}
          <div className="grid grid-cols-1 items-start gap-3.5 md:grid-cols-4">
            {tiers.map((t) => (
              <TierCard key={t.name} tier={t} cycle={cycle} />
            ))}
          </div>
        </div>
      </section>

      <ComparisonTable />
      <PricingFAQ />
    </>
  );
}

function CycleToggle({ value, onChange }: { value: BillingCycle; onChange: (v: BillingCycle) => void }) {
  return (
    <div className="mt-8 inline-flex items-center gap-1 rounded-full border-[0.5px] border-ink/10 bg-mist p-1">
      <button
        onClick={() => onChange('monthly')}
        className={`rounded-full px-4 py-2 text-[13px] font-medium transition-colors duration-200 ${
          value === 'monthly' ? 'bg-paper text-ink' : 'text-ink-500 hover:text-ink'
        }`}
      >
        Monthly
      </button>
      <button
        onClick={() => onChange('yearly')}
        className={`rounded-full px-4 py-2 text-[13px] font-medium transition-colors duration-200 ${
          value === 'yearly' ? 'bg-paper text-ink' : 'text-ink-500 hover:text-ink'
        }`}
      >
        Yearly
        <span className="ml-1.5 rounded-full bg-emerald-brand-100 px-1.5 py-0.5 text-[10px] font-medium text-emerald-brand-600">
          −2 mo
        </span>
      </button>
    </div>
  );
}

/**
 * Side-effect indirection so tests can swap window.location.assign.
 * We can't stub `window.location` reliably in jsdom without breaking
 * other navigation, so we route every Stripe redirect through this wrapper.
 */
export const externalRedirect: { go: (url: string) => void } = {
  go: (url: string) => {
    window.location.assign(url);
  },
};

function TierCard({ tier, cycle }: { tier: Tier; cycle: BillingCycle }) {
  const navigate = useNavigate();
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  const checkout = useSubscriptionStore((s) => s.checkout);
  const isStartingCheckout = useSubscriptionStore((s) => s.isStartingCheckout);
  const error = useSubscriptionStore((s) => s.error);
  const plan = TIER_TO_PLAN[tier.name];
  const isEnterprise = plan === 'enterprise';
  const isFree = plan === 'free';
  const isPaid = plan === 'starter' || plan === 'pro';

  async function handleCta(e: React.MouseEvent) {
    if (isFree || isEnterprise) {
      // Let the underlying anchor handle navigation/mailto.
      return;
    }
    e.preventDefault();
    if (!isAuthenticated) {
      // Carry the chosen plan + cycle into the registration flow so the
      // user lands back here with their selection preserved.
      navigate(`/register?plan=${plan}&cycle=${cycle}`);
      return;
    }
    try {
      const url = await checkout(plan as Exclude<Plan, 'free'>, cycle);
      externalRedirect.go(url);
    } catch {
      // Error already in subscriptionStore.error and rendered below.
    }
  }

  const raw = cycle === 'monthly' ? tier.monthly : tier.yearly;
  const priceLabel =
    raw === 'custom'
      ? 'Custom'
      : raw === 0
        ? '€0'
        : `€${cycle === 'yearly' ? Math.round(raw / 12) : raw}`;
  const suffix = raw === 'custom' ? undefined : '/mo';

  return (
    <div
      className={`relative rounded-xl bg-paper ${
        tier.highlight ? 'border-2 border-indigo p-[27px_23px]' : 'border-[0.5px] border-ink/14 p-[28px_24px]'
      }`}
    >
      {tier.highlight && (
        <span className="absolute left-1/2 -top-3.5 -translate-x-1/2 rounded-full bg-sand px-3.5 py-1 text-[11px] font-medium tracking-[0.04em] text-[#A35412]">
          Most popular
        </span>
      )}
      <div className="mb-2.5 text-[12px] font-medium tracking-[0.04em] text-indigo">
        {tier.name}
      </div>
      <div className="text-[40px] font-medium leading-none tracking-[-0.02em] text-ink">
        {priceLabel}
        {suffix && <span className="text-[14px] font-normal text-ink-400">{suffix}</span>}
      </div>
      <div className="mt-1.5 min-h-9 text-[13px] text-ink-500">
        {tier.rowsPerMonth}
        {cycle === 'yearly' && typeof tier.yearly === 'number' && tier.yearly > 0 && (
          <span className="ml-1 text-ink-400">· €{tier.yearly}/yr</span>
        )}
      </div>
      <ul className="mt-5 mb-5.5 space-y-1 text-[13.5px]">
        {tier.features.map((f) => (
          <li key={f} className="flex items-start gap-2 py-0.5 text-ink-700">
            <EmeraldCheck className="mt-1 shrink-0" /> {f}
          </li>
        ))}
      </ul>
      <Link
        to={
          isEnterprise
            ? 'mailto:sales@feedgenix.com'
            : isFree
              ? '/register'
              : isAuthenticated
                ? '#'                                  // handler does the work
                : `/register?plan=${plan}&cycle=${cycle}`
        }
        onClick={handleCta}
        className={`block w-full rounded-lg py-2.5 text-center text-[14px] font-medium transition-colors duration-200 ${
          tier.highlight
            ? 'bg-indigo text-white hover:bg-indigo-600'
            : 'border-[0.5px] border-ink/14 bg-paper text-ink hover:bg-mist'
        } ${isPaid && isStartingCheckout ? 'pointer-events-none opacity-70' : ''}`}
        aria-busy={isPaid && isStartingCheckout}
      >
        {isPaid && isStartingCheckout ? 'Starting checkout…' : tier.cta}
      </Link>
    </div>
  );
}

/* ───────────────────────── COMPARISON TABLE ───────────────────────── */

function ComparisonTable() {
  const rows: Array<{ label: string; values: Array<string | boolean> }> = [
    { label: 'Rows per month',       values: ['500', '10,000', '100,000', 'Unlimited'] },
    { label: 'Target languages',     values: ['3', '10', 'Unlimited', 'Unlimited'] },
    { label: 'Team members',         values: ['1', '3', '5', 'Unlimited'] },
    { label: 'CSV export',           values: [true, true, true, true] },
    { label: 'XLSX + JSON export',   values: [false, true, true, true] },
    { label: 'Change detection',     values: [false, true, true, true] },
    { label: 'API + webhooks',       values: [false, false, true, true] },
    { label: 'Shopify push',         values: [false, false, true, true] },
    { label: 'Priority routing',     values: [false, false, true, true] },
    { label: 'SSO / SCIM',           values: [false, false, false, true] },
    { label: 'SOC 2 Type II report', values: [false, false, false, true] },
    { label: 'Dedicated success',    values: [false, false, false, true] },
  ];
  return (
    <section className="bg-mist">
      <div className="mx-auto max-w-300 px-10 py-20">
        <h2 className="mb-8 text-center text-[32px] font-medium tracking-[-0.02em] text-ink">
          Compare all features
        </h2>
        <div className="overflow-hidden rounded-xl border-[0.5px] border-ink/10 bg-paper">
          <div className="grid grid-cols-[2fr_1fr_1fr_1fr_1fr] bg-[#FAFAF7] px-5 py-3 text-[11px] font-medium tracking-[0.05em] text-ink-400">
            <span>FEATURE</span>
            {tiers.map((t) => (
              <span key={t.name} className="text-center">
                {t.name.toUpperCase()}
              </span>
            ))}
          </div>
          {rows.map((r) => (
            <div
              key={r.label}
              className="grid grid-cols-[2fr_1fr_1fr_1fr_1fr] items-center border-t-[0.5px] border-ink/10 px-5 py-3.5 text-[13.5px]"
            >
              <span className="text-ink-700">{r.label}</span>
              {r.values.map((v, i) => (
                <span key={i} className="text-center">
                  {typeof v === 'boolean' ? (
                    v ? <EmeraldCheck className="inline-block" /> : <span className="text-ink-300">—</span>
                  ) : (
                    <span className="text-ink-700">{v}</span>
                  )}
                </span>
              ))}
            </div>
          ))}
        </div>
      </div>
    </section>
  );
}

/* ───────────────────────── PRICING FAQ ───────────────────────── */

function PricingFAQ() {
  const items = [
    {
      q: 'What counts as a row?',
      a: 'One product row in your CSV — whichever unique SKU/external_id it carries. We count rows that get translated or re-translated; cached hits do not consume your quota.',
    },
    {
      q: 'Do cache hits count against my monthly rows?',
      a: "No. If a phrase has been translated before — anywhere in your workspace — we serve it from cache for free. That's the entire point of the Golden Rule plus smart cache combo.",
    },
    {
      q: 'Can I switch plans at any time?',
      a: 'Yes. Upgrades are prorated within the billing cycle; downgrades take effect at the next renewal. No annual lock-in on Starter and Pro.',
    },
    {
      q: 'What happens if I exceed my row quota?',
      a: 'We pause new translations and notify you before any overage. You can either wait for the next billing cycle or upgrade in-place.',
    },
  ];
  return (
    <section className="bg-paper">
      <div className="mx-auto max-w-200 px-10 py-20">
        <h2 className="mb-8 text-center text-[32px] font-medium tracking-[-0.02em] text-ink">
          Pricing questions
        </h2>
        <div className="divide-y-[0.5px] divide-ink/10">
          {items.map((it) => (
            <div key={it.q} className="py-6">
              <h3 className="text-[16px] font-medium text-ink">{it.q}</h3>
              <p className="mt-2 text-[15px] leading-[1.6] text-ink-500">{it.a}</p>
            </div>
          ))}
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
