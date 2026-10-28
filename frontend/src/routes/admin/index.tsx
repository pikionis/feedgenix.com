import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Card } from '~/components/ui/card'
import { PageHeader } from '~/components/ui/page-header'
import { adminOverviewQuery, twoFactorStatusQuery } from '~/features/admin/queries'
import { TwoFactorCodeForm } from '~/features/admin/TwoFactorCodeForm'
import { TwoFactorSetup } from '~/features/admin/TwoFactorSetup'
import { api } from '~/lib/api/client'
import type { Route } from './+types/index'

export const meta: Route.MetaFunction = () => [{ title: 'Staff — FeedGenix' }, { name: 'robots', content: 'noindex' }]

export default function AdminHome() {
  const client = useQueryClient()
  const status = useQuery(twoFactorStatusQuery)
  const refresh = () => client.invalidateQueries({ queryKey: twoFactorStatusQuery.queryKey })

  if (status.data === undefined) return <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />

  if (!status.data.enabled) {
    return (
      <Card className="max-w-lg">
        <h1 className="mb-4 text-xl font-medium tracking-tight">Two-factor sign-in</h1>
        <TwoFactorSetup onDone={() => void refresh()} />
      </Card>
    )
  }

  if (!status.data.passed) {
    return (
      <Card className="max-w-lg">
        <h1 className="mb-1 text-xl font-medium tracking-tight">Confirm it is you</h1>
        <p className="mb-5 text-sm text-muted-foreground">The staff area asks for a code once per session.</p>
        <TwoFactorCodeForm
          label="Code"
          hint="From your authenticator app, or one of your recovery codes."
          submitLabel="Continue"
          onSubmit={async (code) => {
            const { error, response } = await api.POST('/v1/security/two-factor/challenge', { body: { code } })
            if (response.ok) await refresh()

            return { ok: response.ok, status: response.status, error }
          }}
        />
      </Card>
    )
  }

  return <Overview />
}

function Overview() {
  const overview = useQuery(adminOverviewQuery)

  const stats = [
    ['Workspaces', overview.data?.workspaces],
    ['Paid workspaces', overview.data?.paid_workspaces],
    ['People', overview.data?.users],
    ['Verified addresses', overview.data?.verified_users],
    ['Target languages', overview.data?.target_languages],
  ] as const

  return (
    <div className="flex flex-col gap-5">
      <PageHeader title="Platform" subtitle="Every workspace, at a glance." />
      <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        {stats.map(([label, value]) => (
          <div key={label} className="flex flex-col rounded-lg bg-surface px-4 py-3.5">
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-1.5 text-[26px] leading-none font-medium tracking-[-0.01em]">
              {value?.toLocaleString('en') ?? '–'}
            </dd>
          </div>
        ))}
      </dl>
    </div>
  )
}
