import { useQuery } from '@tanstack/react-query'
import { Check } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { Button } from '~/components/ui/button'
import type { Plan } from '~/lib/api/types'
import { formatBytes } from '~/lib/format'
import { cn } from '~/lib/utils'
import { plansQuery } from '~/features/workspace/queries'
import { euros, quantity } from './format'

export type BillingPeriod = 'monthly' | 'yearly'

export function highlights(plan: Plan): string[] {
  const lines = [
    quantity(plan.limits.feeds, plan.limits.feeds === 1 ? 'feed' : 'feeds'),
    quantity(plan.limits.languages, plan.limits.languages === 1 ? 'language' : 'languages'),
    plan.limits.products_per_month === null
      ? 'Unlimited products'
      : `${plan.limits.products_per_month.toLocaleString('en')} new or changed products a month`,
    plan.limits.feed_bytes === null ? 'Feeds of any size' : `Feeds up to ${formatBytes(plan.limits.feed_bytes)} each`,
  ]
  if (plan.features.ai_agent) lines.push('Your own AI agent and private memory')
  if (plan.features.field_instructions) lines.push('Per-field instructions')
  if (plan.features.dedicated_infrastructure) lines.push('Dedicated infrastructure')
  if (plan.features.api_mcp) lines.push('API and MCP access')

  return lines
}

/** Where a plan's button leads: the confirmation step before payment. */
export function checkoutPath(plan: string, period: BillingPeriod): string {
  return `/app/billing/checkout?plan=${encodeURIComponent(plan)}&period=${period}`
}

/** The monthly / yearly switch, shared by the plan cards and the checkout step. */
export function PeriodSwitch({ value, onChange }: { value: BillingPeriod; onChange: (period: BillingPeriod) => void }) {
  return (
    <fieldset className="inline-flex self-start rounded-lg border-[0.5px] border-hairline bg-background p-0.5 text-sm">
      <legend className="sr-only">Billing period</legend>
      {(['monthly', 'yearly'] as const).map((option) => (
        <button
          key={option}
          type="button"
          aria-pressed={value === option}
          onClick={() => onChange(option)}
          className={cn(
            'h-8 rounded-md px-3 transition-colors duration-200',
            value === option ? 'bg-ink font-medium text-on-ink' : 'text-muted-foreground hover:text-foreground',
          )}
        >
          {option === 'yearly' ? 'Yearly · save 25%' : 'Monthly'}
        </button>
      ))}
    </fieldset>
  )
}

/** The four plans with a monthly / yearly switch; each leads to the confirmation step. */
export function PlanCards({ current }: { current: string }) {
  const plans = useQuery(plansQuery)
  const [period, setPeriod] = useState<BillingPeriod>('yearly')
  const yearly = period === 'yearly'

  if (plans.data === undefined) return <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />

  return (
    <div className="flex flex-col gap-4">
      <PeriodSwitch value={period} onChange={setPeriod} />
      <div className="grid gap-3.5 md:grid-cols-2 xl:grid-cols-4">
        {plans.data.plans.map((plan) => {
          const recommended = plan.key === plans.data.recommended
          const isCurrent = plan.key === current
          const free = plan.price_monthly_eur === 0

          return (
            <article
              key={plan.key}
              aria-label={`${plan.name} plan`}
              className={cn(
                'flex flex-col rounded-xl bg-background',
                recommended ? 'border-2 border-primary p-[calc(1.5rem-1.5px)]' : 'border-[0.5px] border-hairline p-6',
              )}
            >
              <div className="flex min-h-6 items-center justify-between gap-2">
                <h3 className="text-md font-medium">{plan.name}</h3>
                {recommended && !isCurrent && (
                  <span className="rounded-full bg-warm px-2.5 py-0.5 text-xs font-medium">Most popular</span>
                )}
              </div>
              <p className="mt-1 min-h-10 text-sm text-muted-foreground">{plan.tagline}</p>
              <p className="mt-4">
                <span className="text-[36px] leading-none font-medium tracking-[-0.02em]">
                  {euros(yearly ? plan.price_yearly_per_month_eur : plan.price_monthly_eur)}
                </span>
                <span className="text-sm text-muted-foreground"> / month</span>
              </p>
              <p className="mt-1 text-xs text-muted-foreground">
                {free
                  ? 'Free for good'
                  : yearly
                    ? `${euros(plan.price_yearly_eur)} billed once a year`
                    : 'Billed monthly'}
              </p>
              <ul className="mt-5 mb-6 flex flex-1 flex-col gap-1.5 text-sm">
                {highlights(plan).map((line) => (
                  <li key={line} className="flex items-start gap-2">
                    <Check size={14} strokeWidth={2.25} className="mt-1 shrink-0 text-accent" aria-hidden="true" />
                    {line}
                  </li>
                ))}
              </ul>
              {isCurrent ? (
                <p className="flex h-10 items-center justify-center rounded-md bg-surface text-sm text-muted-foreground">
                  Your plan
                </p>
              ) : free ? (
                <Button variant="secondary" className="h-10 w-full" asChild>
                  <Link to="/app/billing">Change in billing</Link>
                </Button>
              ) : (
                <Button variant={recommended ? 'primary' : 'secondary'} className="h-10 w-full" asChild>
                  <Link to={checkoutPath(plan.key, period)}>Choose {plan.name}</Link>
                </Button>
              )}
            </article>
          )
        })}
      </div>
    </div>
  )
}
