import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { Button } from '~/components/ui/button'
import { Dialog, DialogClose } from '~/components/ui/dialog'
import { Field } from '~/components/ui/field'
import { Input } from '~/components/ui/input'
import { plansQuery } from '~/features/workspace/queries'
import { api } from '~/lib/api/client'
import type { AdminWorkspace } from '~/lib/api/types'
import { applyServerErrors, describeFailure, zodResolver } from '~/lib/forms'
import { adminAuditQuery } from './queries'

const select =
  'h-9 rounded-md border-[0.5px] border-hairline bg-background px-2 text-sm focus-visible:border-primary focus-visible:ring-1 focus-visible:ring-primary focus-visible:outline-none disabled:opacity-60'

function useRefresh() {
  const client = useQueryClient()

  return () =>
    Promise.all([
      // The list's pages and this workspace's detail share the prefix: one refetch each.
      client.invalidateQueries({ queryKey: ['admin', 'workspaces'] }),
      client.invalidateQueries({ queryKey: adminAuditQuery.queryKey }),
    ])
}

const grantSchema = z.object({
  euros: z
    .string()
    .trim()
    .regex(/^\d{1,5}(?:\.\d{1,4})?$/, { error: 'A positive amount in euros, like 10 or 2.50.' }),
  note: z.string().trim().min(3, { error: 'Say why, in a few words.' }).max(255),
})

/** Credits for a workspace, worth an amount in euros, with why (the private-beta AI budget, a goodwill gesture). */
export function GrantCreditsForm({ workspace }: { workspace: AdminWorkspace['workspace'] }) {
  const refresh = useRefresh()
  const [granted, setGranted] = useState<string>()
  const form = useForm({ resolver: zodResolver(grantSchema), defaultValues: { euros: '', note: '' } })
  const { errors, isSubmitting } = form.formState

  const onSubmit = form.handleSubmit(async (values) => {
    setGranted(undefined)
    const { data, error, response } = await api.POST('/v1/admin/workspaces/{workspace}/credits', {
      params: { path: { workspace: String(workspace.id) } },
      body: values,
    })
    if (data !== undefined) {
      setGranted(
        `Granted ${data.granted.toLocaleString('en')} credits. Available now: ${data.available.toLocaleString('en')}.`,
      )
      form.reset()
      await refresh()
      return
    }
    if (!applyServerErrors(error, form.setError, ['euros', 'note'])) {
      form.setError('root.server', { message: describeFailure(response.status) })
    }
  })

  return (
    <form onSubmit={onSubmit} noValidate aria-label="Grant credits" className="flex flex-col gap-3">
      <h3 className="text-sm font-medium">Grant credits</h3>
      <Field
        label="Worth, in euros"
        hint="Up to 10,000. They go into the same wallet as purchases."
        error={errors.euros?.message}
      >
        {(control) => <Input inputMode="decimal" autoComplete="off" {...control} {...form.register('euros')} />}
      </Field>
      <Field label="Why" error={errors.note?.message ?? errors.root?.server?.message}>
        {(control) => <Input autoComplete="off" maxLength={255} {...control} {...form.register('note')} />}
      </Field>
      <div className="flex flex-wrap items-center gap-3">
        <Button type="submit" disabled={isSubmitting}>
          {isSubmitting ? 'Granting…' : 'Grant credits'}
        </Button>
        {granted !== undefined && <output className="text-sm text-success">{granted}</output>}
      </div>
    </form>
  )
}

interface PlanChoice {
  plan: string
  interval: 'month' | 'year'
  until: string
}

/** The plan set by hand until Stripe: confirmed first, because what is over a smaller plan pauses at once. */
export function SetPlanForm({ workspace }: { workspace: AdminWorkspace['workspace'] }) {
  const plans = useQuery(plansQuery)
  const refresh = useRefresh()
  const [choice, setChoice] = useState<PlanChoice>({
    plan: workspace.plan,
    interval: workspace.interval === 'year' ? 'year' : 'month',
    until: '',
  })
  const [confirming, setConfirming] = useState(false)
  const [busy, setBusy] = useState(false)
  const [outcome, setOutcome] = useState<{ tone: 'success' | 'error'; text: string }>()
  const chosen = plans.data?.plans.find((plan) => plan.key === choice.plan)
  const name = chosen?.name ?? choice.plan
  const free = choice.plan === 'free'
  // Once, when the form opens: the earliest paid-until date the API takes.
  const [tomorrow] = useState(() => new Date(Date.now() + 86_400_000).toISOString().slice(0, 10))

  const apply = async () => {
    if (chosen === undefined) return
    setBusy(true)
    setOutcome(undefined)
    const { data, error, response } = await api.POST('/v1/admin/workspaces/{workspace}/plan', {
      params: { path: { workspace: String(workspace.id) } },
      body: {
        plan: chosen.key,
        interval: choice.interval,
        ...(free || choice.until === '' ? {} : { until: choice.until }),
      },
    })
    setBusy(false)
    setConfirming(false)
    if (data === undefined) {
      const message =
        typeof error === 'object' && error !== null && 'message' in error && typeof error.message === 'string'
          ? error.message
          : describeFailure(response.status)
      setOutcome({ tone: 'error', text: message })
      return
    }
    const paused = data.feeds_paused + data.languages_paused
    const resumed = data.feeds_resumed + data.languages_resumed
    setOutcome({
      tone: 'success',
      text: [
        `On ${data.plan_name} now.`,
        paused > 0 && `Paused ${count(data.feeds_paused, 'feed')}, ${count(data.languages_paused, 'language')}.`,
        resumed > 0 && `Resumed ${count(data.feeds_resumed, 'feed')}, ${count(data.languages_resumed, 'language')}.`,
      ]
        .filter(Boolean)
        .join(' '),
    })
    await refresh()
  }

  return (
    <form
      aria-label="Change the plan"
      className="flex flex-col gap-3"
      onSubmit={(event) => {
        event.preventDefault()
        setConfirming(true)
      }}
    >
      <h3 className="text-sm font-medium">Change the plan</h3>
      <div className="flex flex-wrap gap-3">
        <label className="flex flex-col gap-1.5 text-sm">
          <span>Plan</span>
          <select
            className={select}
            value={choice.plan}
            onChange={(event) => setChoice({ ...choice, plan: event.target.value })}
          >
            {(plans.data?.plans ?? []).map((plan) => (
              <option key={plan.key} value={plan.key}>
                {plan.name}
              </option>
            ))}
          </select>
        </label>
        <label className="flex flex-col gap-1.5 text-sm">
          <span>Billed</span>
          <select
            className={select}
            value={choice.interval}
            disabled={free}
            onChange={(event) => setChoice({ ...choice, interval: event.target.value === 'year' ? 'year' : 'month' })}
          >
            <option value="month">Monthly</option>
            <option value="year">Yearly</option>
          </select>
        </label>
        <label className="flex flex-col gap-1.5 text-sm">
          <span>Paid until</span>
          <input
            type="date"
            className={select}
            value={choice.until}
            min={tomorrow}
            disabled={free}
            onChange={(event) => setChoice({ ...choice, until: event.target.value })}
          />
        </label>
      </div>
      <p className="text-xs text-faint">
        {free ? 'Free has no term.' : 'Without a date: one month or one year from today.'}
      </p>
      <div className="flex flex-wrap items-center gap-3">
        <Button type="submit" variant="secondary" disabled={chosen === undefined || busy}>
          Change the plan
        </Button>
        {outcome !== undefined && (
          <output className={outcome.tone === 'success' ? 'text-sm text-success' : 'text-sm text-error'}>
            {outcome.text}
          </output>
        )}
      </div>

      <Dialog
        open={confirming}
        onOpenChange={setConfirming}
        title={`Put ${workspace.name} on ${name}?`}
        description={
          free
            ? 'Feeds and languages over Free’s limits pause at once. Nothing is deleted, and their feed URLs keep serving the last build.'
            : `${choice.interval === 'year' ? 'Yearly' : 'Monthly'}, paid until ${choice.until === '' ? (choice.interval === 'year' ? 'a year' : 'a month') + ' from today' : choice.until}. What is over the plan’s limits pauses at once; what fits again resumes.`
        }
      >
        <div className="flex justify-end gap-2">
          <DialogClose asChild>
            <Button type="button" variant="secondary">
              Cancel
            </Button>
          </DialogClose>
          <Button type="button" disabled={busy} onClick={() => void apply()}>
            {busy ? 'Changing…' : `Put on ${name}`}
          </Button>
        </div>
      </Dialog>
    </form>
  )
}

function count(value: number, noun: string): string {
  return `${value} ${noun}${value === 1 ? '' : 's'}`
}
