import { useQuery } from '@tanstack/react-query'
import { Check } from 'lucide-react'
import { Link } from 'react-router'
import { Button } from '~/components/ui/button'
import { highlights } from '~/features/billing/PlanCards'
import { euros } from '~/features/billing/format'
import { plansQuery } from '~/features/workspace/queries'
import type { Plan } from '~/lib/api/types'
import { cn } from '~/lib/utils'

const CREDIT_FACTS = [
  ['An estimate first', 'Every job shows what it will use before it starts, and is charged only what it used.'],
  ['Credits never expire', 'Prepaid in packs of €10, €50 or €100, or the amount you choose.'],
  ['From memory, at no cost', 'A text translated once is reused, never paid for twice.'],
] as const

/**
 * The public plans, read live from /api/plans so the page can never show a stale price. Prices,
 * public limits and features only: never a cost, a provider price or the credit markup.
 */
export function Pricing() {
  const plans = useQuery(plansQuery)

  return (
    <section id="pricing" aria-labelledby="pricing-title" className="py-16 lg:py-20">
      <h2 id="pricing-title" className="mb-1.5 text-center text-2xl font-medium tracking-[-0.02em]">
        Pricing built around products, not seats.
      </h2>
      <p className="mb-11 text-center text-base text-muted-foreground">
        Every plan keeps the Golden Rule. AI work is paid from prepaid credits, with an estimate before any job starts.
      </p>
      {plans.isError ? (
        <p role="alert" className="text-center text-sm text-muted-foreground">
          The plans could not be loaded. Reload the page in a moment.
        </p>
      ) : plans.data === undefined ? (
        <div className="mx-auto h-0.5 w-40 animate-pulse bg-primary" aria-hidden="true" />
      ) : (
        <>
          <div className="grid items-start gap-3.5 sm:grid-cols-2 lg:grid-cols-4">
            {plans.data.plans.map((plan) => (
              <PlanCard key={plan.key} plan={plan} recommended={plan.key === plans.data.recommended} />
            ))}
          </div>
          <ul className="mt-10 grid gap-3 md:grid-cols-3">
            {CREDIT_FACTS.map(([title, body]) => (
              <li key={title} className="rounded-lg bg-surface p-5">
                <p className="font-medium">{title}</p>
                <p className="mt-1 text-sm text-muted-foreground">{body}</p>
              </li>
            ))}
          </ul>
          <p className="mt-6 text-center text-sm text-faint">
            During the beta every account starts on Free; paid plans open with checkout.
          </p>
        </>
      )}
    </section>
  )
}

function PlanCard({ plan, recommended }: { plan: Plan; recommended: boolean }) {
  const free = plan.price_monthly_eur === 0

  return (
    <article
      aria-label={`${plan.name} plan`}
      className={cn(
        'relative rounded-xl bg-background',
        recommended
          ? 'border-2 border-primary px-[calc(--spacing(6)-1.5px)] py-[calc(--spacing(7)-1.5px)]'
          : 'border-[0.5px] border-hairline px-6 py-7',
      )}
    >
      {recommended && (
        <span className="absolute -top-3.5 left-1/2 -translate-x-1/2 rounded-full bg-warm px-3.5 py-1 text-xs font-medium whitespace-nowrap text-warn">
          Most popular
        </span>
      )}
      <h3 className="mb-2.5 text-xs font-medium tracking-[0.04em] text-primary">{plan.name}</h3>
      <p className="text-[40px] leading-none font-medium tracking-[-0.02em]">
        {euros(plan.price_monthly_eur)}
        {!free && <span className="text-sm font-normal tracking-normal text-faint">/mo</span>}
      </p>
      <p className="mt-1.5 min-h-10 text-sm text-muted-foreground">
        {plan.tagline}
        <span className="block text-faint">
          {free
            ? 'Free for good'
            : `or ${euros(plan.price_yearly_per_month_eur)}/mo billed yearly, ${euros(plan.price_yearly_eur)} a year`}
        </span>
      </p>
      <ul className="mt-5 mb-6 flex flex-col gap-1.5 text-sm">
        {highlights(plan).map((line) => (
          <li key={line} className="flex items-start gap-2">
            <Check size={14} strokeWidth={2.25} className="mt-0.75 shrink-0 text-accent" aria-hidden="true" />
            {line}
          </li>
        ))}
      </ul>
      <Button variant={recommended ? 'primary' : 'secondary'} className="w-full" asChild>
        <Link to="/register">{free ? 'Start free' : 'Start free, upgrade later'}</Link>
      </Button>
    </article>
  )
}
