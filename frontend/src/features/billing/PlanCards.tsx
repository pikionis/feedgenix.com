import { useQuery } from '@tanstack/react-query'
import { Check } from 'lucide-react'
import { useState } from 'react'
import { Button } from '~/components/ui/button'
import type { Plan } from '~/lib/api/types'
import { formatBytes } from '~/lib/format'
import { cn } from '~/lib/utils'
import { plansQuery } from '~/features/workspace/queries'
import { euros, quantity } from './format'

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

/** The four plans with a monthly / yearly switch. Checkout arrives with billing (M6). */
export function PlanCards({ current }: { current: string }) {
  const plans = useQuery(plansQuery)
  const [yearly, setYearly] = useState(true)

  if (plans.data === undefined) return <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />

  return (
    <div className="flex flex-col gap-4">
      <fieldset className="inline-flex self-start rounded-md bg-surface p-0.5 text-sm">
        <legend className="sr-only">Billing period</legend>
        {[false, true].map((option) => (
          <button
            key={String(option)}
            type="button"
            aria-pressed={yearly === option}
            onClick={() => setYearly(option)}
            className={cn(
              'rounded px-3 py-1',
              yearly === option ? 'bg-background font-medium' : 'text-muted-foreground',
            )}
          >
            {option ? 'Yearly · save 25%' : 'Monthly'}
          </button>
        ))}
      </fieldset>
      <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        {plans.data.plans.map((plan) => {
          const recommended = plan.key === plans.data.recommended
          const isCurrent = plan.key === current

          return (
            <article
              key={plan.key}
              aria-label={`${plan.name} plan`}
              className={cn(
                'flex flex-col rounded-xl bg-background p-5',
                recommended ? 'border-2 border-primary' : 'border-[0.5px] border-hairline',
              )}
            >
              <div className="flex items-center justify-between">
                <h3 className="font-medium">{plan.name}</h3>
                {recommended && <span className="rounded-full bg-warm px-2 py-0.5 text-xs">Most popular</span>}
              </div>
              <p className="mt-1 text-sm text-muted-foreground">{plan.tagline}</p>
              <p className="mt-4">
                <span className="text-2xl font-medium">
                  {euros(yearly ? plan.price_yearly_per_month_eur : plan.price_monthly_eur)}
                </span>
                <span className="text-sm text-muted-foreground"> / month</span>
              </p>
              <p className="text-xs text-faint">
                {plan.price_monthly_eur === 0
                  ? 'Free for good'
                  : yearly
                    ? `${euros(plan.price_yearly_eur)} billed once a year`
                    : 'Billed monthly'}
              </p>
              <ul className="mt-4 flex flex-col gap-1.5 text-sm">
                {highlights(plan).map((line) => (
                  <li key={line} className="flex items-start gap-2">
                    <Check size={14} strokeWidth={2.25} className="mt-1 shrink-0 text-accent" aria-hidden="true" />
                    {line}
                  </li>
                ))}
              </ul>
              <div className="mt-5 pt-1">
                {isCurrent ? (
                  <p className="text-sm text-muted-foreground">Your plan</p>
                ) : (
                  <Button variant={recommended ? 'primary' : 'secondary'} className="w-full" disabled>
                    Checkout opens soon
                  </Button>
                )}
              </div>
            </article>
          )
        })}
      </div>
    </div>
  )
}
