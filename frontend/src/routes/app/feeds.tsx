import { useQuery } from '@tanstack/react-query'
import { ChevronRight, Link2, Upload } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { Button } from '~/components/ui/button'
import { Card } from '~/components/ui/card'
import { Notice } from '~/components/ui/notice'
import { UsageBar } from '~/components/ui/usage-bar'
import { AddFeedDialog } from '~/features/feeds/AddFeedDialog'
import { FORMAT, formatWhen } from '~/features/feeds/labels'
import { feedsQuery } from '~/features/feeds/queries'
import { StatusBadge } from '~/features/feeds/StatusBadge'
import { workspaceQuery } from '~/features/workspace/queries'
import type { Feed } from '~/lib/api/types'
import { useSession } from '~/lib/session'
import type { Route } from './+types/feeds'

export const meta: Route.MetaFunction = () => [{ title: 'Feeds — FeedGenix' }, { name: 'robots', content: 'noindex' }]

export default function Feeds() {
  const session = useSession()
  const feeds = useQuery(feedsQuery)
  const workspace = useQuery(workspaceQuery)
  const [adding, setAdding] = useState(false)
  const role = session.data?.workspace?.role
  const canManage = role === 'owner' || role === 'admin'

  return (
    <div className="flex flex-col gap-6">
      <div className="flex flex-wrap items-end justify-between gap-4">
        <div>
          <h1 className="text-2xl font-medium tracking-tight">Feeds</h1>
          <p className="mt-1 text-muted-foreground">Where your products come from.</p>
        </div>
        {canManage && <Button onClick={() => setAdding(true)}>Add a feed</Button>}
      </div>

      {workspace.data !== undefined && (
        <div className="max-w-sm">
          <UsageBar
            label="Feeds on your plan"
            used={workspace.data.entitlements.used.feeds}
            allowed={workspace.data.entitlements.limits.feeds}
          />
        </div>
      )}

      {feeds.isError && <Notice tone="error">{feeds.error.message}</Notice>}
      {feeds.data?.length === 0 && (
        <Card className="flex flex-col items-start gap-3">
          <h2 className="font-medium">No feeds yet</h2>
          <p className="text-sm text-muted-foreground">
            Add the address your shop publishes its products at, or upload an export: CSV, XML, JSON or Excel. We read
            it and show you every column, before anything is translated.
          </p>
          {canManage ? (
            <Button onClick={() => setAdding(true)}>Add your first feed</Button>
          ) : (
            <p className="text-sm text-faint">The owner or an admin of the workspace adds feeds.</p>
          )}
        </Card>
      )}
      {feeds.data !== undefined && feeds.data.length > 0 && (
        <ul className="flex flex-col overflow-hidden rounded-xl border-[0.5px] border-hairline bg-background">
          {feeds.data.map((feed) => (
            <FeedRow key={feed.id} feed={feed} />
          ))}
        </ul>
      )}

      {adding && <AddFeedDialog onClose={() => setAdding(false)} />}
    </div>
  )
}

function FeedRow({ feed }: { feed: Feed }) {
  const latest = feed.latest_import
  const Icon = feed.source === 'url' ? Link2 : Upload
  const facts = [feed.source === 'url' ? (feed.host ?? '') : 'Uploaded files']
  if (latest !== null && latest.format !== null) facts.push(FORMAT[latest.format])
  if (latest !== null && latest.row_count !== null) facts.push(`${latest.row_count.toLocaleString('en')} products`)
  if (latest !== null && latest.column_count !== null) facts.push(`${latest.column_count.toLocaleString('en')} columns`)

  return (
    <li className="border-t-[0.5px] border-hairline first:border-t-0">
      <Link
        to={`/app/feeds/${feed.id}`}
        className="flex items-center gap-4 px-5 py-3.5 transition-colors duration-200 hover:bg-subtle"
      >
        <span className="flex size-8 shrink-0 items-center justify-center rounded-md bg-surface text-muted-foreground">
          <Icon size={16} strokeWidth={1.5} aria-hidden="true" />
        </span>
        <div className="flex min-w-0 flex-1 flex-col gap-0.5">
          <span className="truncate font-medium">{feed.name}</span>
          <span className="truncate text-sm text-muted-foreground">{facts.join(' · ')}</span>
        </div>
        <div className="flex shrink-0 flex-col items-end gap-1">
          {latest !== null ? (
            <StatusBadge status={latest.status} />
          ) : (
            <span className="text-sm text-faint">No file yet</span>
          )}
          {latest !== null && <span className="text-xs text-faint">{formatWhen(latest.created_at)}</span>}
        </div>
        <ChevronRight size={16} strokeWidth={1.5} className="shrink-0 text-faint" aria-hidden="true" />
      </Link>
    </li>
  )
}
