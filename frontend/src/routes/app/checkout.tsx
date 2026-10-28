import { useQuery } from '@tanstack/react-query'
import { ArrowLeft, Check, Lock } from 'lucide-react'
import { Link, useSearchParams } from 'react-router'
import { Button } from '~/components/ui/button'
import { Card } from '~/components/ui/card'
import { euros } from '~/features/billing/format'
import { type BillingPeriod, highlights, PeriodSwitch } from '~/features/billing/PlanCards'
import { plansQuery, workspaceQuery } from '~/features/workspace/queries'
import type { Route } from './+types/checkout'

export const meta: Route.MetaFunction = () => [
  { title: 'Confirm your plan — FeedGenix' },
  { name: 'robots', content: 'noindex' },
]

/**
 * The step before payment: the plan, the period and what is due. Payment itself happens on Stripe's
 * hosted Checkout (ADR 0008, M6); until it is wired the button says so and nothing is charged.
 */
export default function Checkout() {
  const [params, setParams] = useSearchParams()
  const plans = useQuery(plansQuery)
  const workspace = useQuery(workspaceQuery)
  const period: BillingPeriod = params.get('period') === 'monthly' ? 'monthly' : 'yearly'
  const plan = plans.data?.plans.find((item) => item.key === params.get('plan'))

  const back = (
    <Link
      to="/app/billing/plans"
      className="inline-flex items-center gap-1 self-start text-sm text-muted-foreground hover:text-foreground"
    >
      <ArrowLeft size={14} strokeWidth={1.5} aria-hidden="true" /> All plans
    </Link>
  )

  if (plans.data === undefined) return <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />
  if (plan === undefined || plan.price_monthly_eur === 0) {
    return (
      <div className="flex flex-col gap-4">
        {back}
        <Card className="flex max-w-xl flex-col gap-2">
          <h1 className="font-medium">Choose a paid plan first</h1>
          <p className="text-sm text-muted-foreground">
            This plan cannot be bought. Pick one of the plans to continue.
          </p>
        </Card>
      </div>
    )
  }

  const yearly = period === 'yearly'
  const amount = yearly ? plan.price_yearly_eur : plan.price_monthly_eur
  const current =
    workspace.data?.plan.key === plan.key && workspace.data.entitlements.interval === (yearly ? 'year' : 'month')

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-3">
        {back}
        <h1 className="text-2xl font-medium tracking-tight">
          {current ? `You are on ${plan.name}` : `Upgrade to ${plan.name}`}
        </h1>
      </div>

      <div className="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_380px]">
        <Card className="flex flex-col gap-4">
          <h2 className="font-medium">Billing period</h2>
          <PeriodSwitch
            value={period}
            onChange={(next) =>
              setParams(
                (previous) => {
                  const updated = new URLSearchParams(previous)
                  updated.set('period', next)
                  return updated
                },
                { replace: true },
              )
            }
          />
          <p className="text-sm text-muted-foreground">
            {yearly
              ? `${euros(plan.price_yearly_eur)} once a year, ${euros(plan.price_yearly_per_month_eur)} a month: you save ${euros(plan.yearly_savings_eur)}.${plan.limits.products_per_year !== null ? ` ${plan.limits.products_per_year.toLocaleString('en')} products in one yearly pool.` : ''}`
              : `${euros(plan.price_monthly_eur)} every month.${plan.limits.products_per_month !== null ? ` ${plan.limits.products_per_month.toLocaleString('en')} products a month.` : ''}`}
          </p>
          <ul className="flex flex-col gap-1.5 border-t-[0.5px] border-hairline pt-4 text-sm">
            {highlights(plan).map((line) => (
              <li key={line} className="flex items-start gap-2">
                <Check size={14} strokeWidth={2.25} className="mt-1 shrink-0 text-accent" aria-hidden="true" />
                {line}
              </li>
            ))}
          </ul>
        </Card>

        <Card className="flex flex-col gap-4 border-2 border-primary p-[calc(1.5rem-1.5px)]" aria-labelledby="summary">
          <h2 id="summary" className="font-medium">
            Summary
          </h2>
          <dl className="flex flex-col gap-2.5 text-sm">
            <div className="flex justify-between gap-3">
              <dt>
                {plan.name} · {yearly ? 'yearly' : 'monthly'}
              </dt>
              <dd className="font-mono text-xs">{euros(amount)}</dd>
            </div>
            <div className="flex justify-between gap-3 text-muted-foreground">
              <dt>VAT</dt>
              <dd>Worked out at payment</dd>
            </div>
            <div className="flex justify-between gap-3 border-t-[0.5px] border-hairline pt-2.5 font-medium">
              <dt>Due today</dt>
              <dd>{euros(amount)} + VAT</dd>
            </div>
          </dl>
          <p className="rounded-md bg-surface px-3 py-2.5 text-sm text-muted-foreground">
            The plan includes no AI spend: translation and mapping use your prepaid credits.
          </p>
          <Button size="lg" disabled>
            <Lock size={16} strokeWidth={1.5} aria-hidden="true" />
            {current ? 'This is your plan' : 'Checkout opens soon'}
          </Button>
          <p className="text-xs text-faint">
            You will pay on Stripe&rsquo;s secure page and come back here. Cancel any time from Billing; the plan runs
            to the end of the paid period.
          </p>
        </Card>
      </div>
    </div>
  )
}
