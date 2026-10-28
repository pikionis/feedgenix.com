import { useQuery } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { Link } from 'react-router'
import { Card } from '~/components/ui/card'
import { PlanCards } from '~/features/billing/PlanCards'
import { plansQuery, workspaceQuery } from '~/features/workspace/queries'
import type { Plan } from '~/lib/api/types'
import { formatBytes } from '~/lib/format'
import type { Route } from './+types/plans'

export const meta: Route.MetaFunction = () => [
  { title: 'Choose a plan — FeedGenix' },
  { name: 'robots', content: 'noindex' },
]

const count = (value: number | null) => (value === null ? 'Unlimited' : value.toLocaleString('en'))
const yes = (value: boolean) => (value ? 'Yes' : '—')

/** The rows of the comparison: what each plan allows, from the live catalogue. */
const ROWS: { label: string; value: (plan: Plan) => string }[] = [
  { label: 'Feeds', value: (plan) => count(plan.limits.feeds) },
  { label: 'Target languages', value: (plan) => count(plan.limits.languages) },
  { label: 'New or changed products a month', value: (plan) => count(plan.limits.products_per_month) },
  {
    label: 'Above the allowance',
    value: (plan) =>
      plan.limits.products_per_month === null
        ? '—'
        : plan.overage_credits_per_product === null
          ? 'Waits for next month'
          : `${plan.overage_credits_per_product.toLocaleString('en')} credits per product`,
  },
  {
    label: 'Largest feed',
    value: (plan) => (plan.limits.feed_bytes === null ? 'Any size' : formatBytes(plan.limits.feed_bytes)),
  },
  {
    label: 'Parallel work',
    value: (plan) => (plan.limits.worker_slots === null ? 'Unlimited' : `${plan.limits.worker_slots} at a time`),
  },
  { label: 'AI translation with quality checks', value: (plan) => yes(plan.features.ai_translation) },
  { label: 'Your own AI agent and private memory', value: (plan) => yes(plan.features.ai_agent) },
  { label: 'Per-field instructions', value: (plan) => yes(plan.features.field_instructions) },
  { label: 'Choice of model', value: (plan) => yes(plan.features.model_choice) },
  { label: 'Priority queue', value: (plan) => yes(plan.features.priority_queue) },
  { label: 'Dedicated infrastructure', value: (plan) => yes(plan.features.dedicated_infrastructure) },
  { label: 'API and MCP access', value: (plan) => yes(plan.features.api_mcp) },
]

export default function Plans() {
  const workspace = useQuery(workspaceQuery)
  const plans = useQuery(plansQuery)

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-3">
        <Link
          to="/app/billing"
          className="inline-flex items-center gap-1 self-start text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft size={14} strokeWidth={1.5} aria-hidden="true" /> Billing
        </Link>
        <div>
          <h1 className="text-2xl font-medium tracking-tight">Choose a plan</h1>
          <p className="mt-1 max-w-2xl text-muted-foreground">
            Upgrade any time. Yearly is paid once and saves 25%. No plan includes AI spend: AI work uses your credits.
          </p>
        </div>
      </div>

      {workspace.data !== undefined && <PlanCards current={workspace.data.plan.key} />}

      {plans.data !== undefined && (
        <Card>
          <h2 className="mb-3 font-medium">Compare the plans</h2>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[720px] text-left text-sm">
              <thead className="text-muted-foreground">
                <tr>
                  <th className="py-2.5 pr-4 font-normal">
                    <span className="sr-only">What is included</span>
                  </th>
                  {plans.data.plans.map((plan) => (
                    <th
                      key={plan.key}
                      className={
                        plan.key === plans.data.recommended
                          ? 'px-3 py-2.5 font-medium text-primary'
                          : 'px-3 py-2.5 font-medium text-foreground'
                      }
                    >
                      {plan.name}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-hairline border-t-[0.5px] border-hairline">
                {ROWS.map((row) => (
                  <tr key={row.label}>
                    <th scope="row" className="py-2.5 pr-4 font-normal text-muted-foreground">
                      {row.label}
                    </th>
                    {plans.data.plans.map((plan) => (
                      <td key={plan.key} className="px-3 py-2.5">
                        {row.value(plan)}
                      </td>
                    ))}
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
          <p className="mt-3 text-sm text-muted-foreground">
            Changing to a smaller plan never deletes anything: feeds and languages above its limits pause, and the last
            export keeps serving.
          </p>
        </Card>
      )}
    </div>
  )
}
