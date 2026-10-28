import { useQuery } from '@tanstack/react-query'
import { PageHeader } from '~/components/ui/page-header'
import { Section } from '~/components/ui/section'
import { Table, TD, TH, THead, TR } from '~/components/ui/table'
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
      <PageHeader
        back={{ to: '/app/billing', label: 'Billing' }}
        title="Choose a plan"
        subtitle="Upgrade any time. Yearly is paid once and saves 25%. No plan includes AI spend: AI work uses your credits."
      />

      {workspace.data !== undefined && <PlanCards current={workspace.data.plan.key} />}

      {plans.data !== undefined && (
        <Section
          title="Compare the plans"
          description="Changing to a smaller plan never deletes anything: feeds and languages above its limits pause, and the last export keeps serving."
        >
          <Table className="min-w-[720px]">
            <THead>
              <tr>
                <TH>
                  <span className="sr-only">What is included</span>
                </TH>
                {plans.data.plans.map((plan) => (
                  <TH
                    key={plan.key}
                    className={plan.key === plans.data.recommended ? 'font-medium text-primary' : 'text-foreground'}
                  >
                    {plan.name}
                  </TH>
                ))}
              </tr>
            </THead>
            <tbody>
              {ROWS.map((row) => (
                <TR key={row.label}>
                  <TH scope="row" className="py-3 text-sm text-muted-foreground">
                    {row.label}
                  </TH>
                  {plans.data.plans.map((plan) => (
                    <TD key={plan.key}>{row.value(plan)}</TD>
                  ))}
                </TR>
              ))}
            </tbody>
          </Table>
        </Section>
      )}
    </div>
  )
}
