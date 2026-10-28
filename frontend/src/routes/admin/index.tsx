import { useQuery } from '@tanstack/react-query'
import { PageHeader } from '~/components/ui/page-header'
import { adminOverviewQuery } from '~/features/admin/queries'
import type { Route } from './+types/index'

export const meta: Route.MetaFunction = () => [{ title: 'Staff — FeedGenix' }, { name: 'robots', content: 'noindex' }]

export default function AdminHome() {
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
