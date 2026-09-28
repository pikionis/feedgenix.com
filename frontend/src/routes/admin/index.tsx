import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Card } from '~/components/ui/card'
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
    <div>
      <h1 className="mb-6 text-2xl font-medium tracking-tight">Platform</h1>
      <dl className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        {stats.map(([label, value]) => (
          <div key={label} className="rounded-lg border-[0.5px] border-hairline bg-background p-5">
            <dt className="text-sm text-muted-foreground">{label}</dt>
            <dd className="mt-1 text-2xl font-medium">{value?.toLocaleString('en') ?? '–'}</dd>
          </div>
        ))}
      </dl>
    </div>
  )
}
