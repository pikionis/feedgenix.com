import { useQuery } from '@tanstack/react-query'
import { CreditCard, FileText } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { Button } from '~/components/ui/button'
import { Card } from '~/components/ui/card'
import { Notice } from '~/components/ui/notice'
import { PageHeader } from '~/components/ui/page-header'
import { UsageBar } from '~/components/ui/usage-bar'
import { euros } from '~/features/billing/format'
import { highlights } from '~/features/billing/PlanCards'
import { TopUpDialog } from '~/features/billing/TopUpDialog'
import { workspaceQuery } from '~/features/workspace/queries'
import type { Route } from './+types/billing'

export const meta: Route.MetaFunction = () => [{ title: 'Billing — FeedGenix' }, { name: 'robots', content: 'noindex' }]

/** "28 October 2027" for the dates of a plan period. */
export function longDate(iso: string): string {
  return new Intl.DateTimeFormat('en-GB', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(iso))
}

export default function Billing() {
  const workspace = useQuery(workspaceQuery)
  const [buying, setBuying] = useState(false)

  if (workspace.data === undefined) {
    return <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />
  }

  const { plan, entitlements, languages, paused_languages: paused } = workspace.data
  const free = plan.price_monthly_eur === 0
  const yearly = entitlements.interval === 'year'
  const role = workspace.data.workspace.role
  const canManage = role === 'owner' || role === 'admin'

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title="Billing"
        subtitle="Your plan is the platform fee. AI work is paid from prepaid credits, with an estimate before every job."
      />

      {paused.length > 0 && (
        <Notice tone="warn">
          {paused.length === 1 ? 'One language is' : `${paused.length} languages are`} over your plan&rsquo;s limit and
          paused: nothing is deleted, they get no new translations until you upgrade or remove another.
        </Notice>
      )}

      <div className="grid gap-4 lg:grid-cols-3">
        <div className="flex flex-col gap-3 rounded-lg bg-surface p-5">
          <h2 className="text-xs text-muted-foreground">Plan</h2>
          <p className="text-[28px] leading-tight font-medium tracking-[-0.02em]">
            {plan.name}
            {!free && ` · ${yearly ? 'yearly' : 'monthly'}`}
          </p>
          <p className="text-sm text-muted-foreground">
            {free
              ? 'Free for good'
              : yearly
                ? `${euros(plan.price_yearly_eur)} billed once a year`
                : `${euros(plan.price_monthly_eur)} billed monthly`}
            {entitlements.paid_until !== null && ` · paid until ${longDate(entitlements.paid_until)}`}
          </p>
          <ul className="flex flex-col gap-1 text-sm">
            {highlights(plan)
              .slice(0, 3)
              .map((line) => (
                <li key={line}>{line}</li>
              ))}
          </ul>
          <div className="mt-auto flex flex-wrap gap-2 pt-2">
            <Button asChild>
              <Link to="/app/billing/plans">{free ? 'Upgrade' : 'Change plan'}</Link>
            </Button>
          </div>
        </div>

        <div className="flex flex-col gap-3 rounded-lg bg-surface p-5">
          <h2 className="text-xs text-muted-foreground">Credits</h2>
          <p className="text-[28px] leading-tight font-medium tracking-[-0.02em]">Prepaid, never expire</p>
          <p className="text-sm text-muted-foreground">
            During the private beta, AI work for your workspace is funded by FeedGenix. Your balance and top-ups show
            here once checkout opens.
          </p>
          <div className="mt-auto flex flex-wrap gap-2 pt-2">
            <Button variant="secondary" disabled={!canManage} onClick={() => setBuying(true)}>
              Buy credits
            </Button>
          </div>
        </div>

        <div className="flex flex-col gap-4 rounded-lg bg-surface p-5">
          <h2 className="text-xs text-muted-foreground">This period</h2>
          <UsageBar label="Feeds" used={entitlements.used.feeds} allowed={entitlements.limits.feeds} />
          <UsageBar
            label="Target languages"
            used={entitlements.used.languages}
            allowed={entitlements.limits.languages}
          />
          <p className="text-sm text-muted-foreground">
            {entitlements.limits.products === null
              ? 'Unlimited new or changed products.'
              : `${entitlements.limits.products.toLocaleString('en')} new or changed products ${yearly ? 'in the yearly pool' : 'a month'}.`}
            {entitlements.overage.available &&
              entitlements.overage.credits_per_product !== null &&
              ` Above it, work continues at ${entitlements.overage.credits_per_product.toLocaleString('en')} credits per extra product.`}
          </p>
          <p className="text-xs text-faint">
            {longDate(entitlements.period.start)} – {longDate(entitlements.period.end)}
          </p>
        </div>
      </div>

      <div className="grid gap-4 lg:grid-cols-2">
        <Card className="flex flex-col gap-3">
          <h2 className="text-[15px] font-medium">Payment method</h2>
          <div className="flex items-center gap-3">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-surface text-muted-foreground">
              <CreditCard size={18} strokeWidth={1.5} aria-hidden="true" />
            </span>
            <p className="text-sm text-muted-foreground">No card yet. You add one when you choose a paid plan.</p>
          </div>
          <p className="text-xs text-faint">
            Cards and cancellation are handled on Stripe&rsquo;s secure customer page. FeedGenix never sees your card
            number.
          </p>
        </Card>
        <Card className="flex flex-col gap-3">
          <h2 className="text-[15px] font-medium">Invoices</h2>
          <div className="flex items-center gap-3">
            <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-surface text-muted-foreground">
              <FileText size={18} strokeWidth={1.5} aria-hidden="true" />
            </span>
            <p className="text-sm text-muted-foreground">No invoices yet. Each payment&rsquo;s invoice appears here.</p>
          </div>
          <p className="text-xs text-faint">VAT is worked out by Stripe Tax from your billing details.</p>
        </Card>
      </div>

      {languages.length === 0 && (
        <p className="text-sm text-muted-foreground">
          No target languages yet.{' '}
          <Link to="/app/settings#languages" className="text-primary hover:text-primary-hover">
            Choose them
          </Link>
          .
        </p>
      )}

      <TopUpDialog open={buying} onOpenChange={setBuying} />
    </div>
  )
}
