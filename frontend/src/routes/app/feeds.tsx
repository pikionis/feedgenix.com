import { useQuery } from '@tanstack/react-query'
import { ChevronRight } from 'lucide-react'
import { useState } from 'react'
import { Link } from 'react-router'
import { Button } from '~/components/ui/button'
import { Callout } from '~/components/ui/callout'
import { Initials } from '~/components/ui/initials'
import { Notice } from '~/components/ui/notice'
import { PageHeader } from '~/components/ui/page-header'
import { Highlight, SECTION_TONES } from '~/components/ui/mosaic'
import { AddFeedDialog } from '~/features/feeds/AddFeedDialog'
import { FORMAT, formatWhen } from '~/features/feeds/labels'
import { feedsQuery } from '~/features/feeds/queries'
import { feedState, FeedStateBadge } from '~/features/feeds/FeedState'
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

  const used = workspace.data?.entitlements.used.feeds
  const limit = workspace.data?.entitlements.limits.feeds
  const latest = feeds.data
    ?.map((feed) => feed.latest_import?.created_at)
    .filter((at): at is string => typeof at === 'string')
    .reduce<string | undefined>((newest, at) => (newest === undefined || at > newest ? at : newest), undefined)

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={<Highlight tone={SECTION_TONES.feeds}>Feeds</Highlight>}
        subtitle={[
          used === undefined
            ? 'Where your products come from'
            : `${used} of ${limit ?? 'unlimited'} feeds on your plan`,
          latest === undefined ? null : `last file ${formatWhen(latest)}`,
        ]
          .filter(Boolean)
          .join(' · ')}
        actions={canManage ? <Button onClick={() => setAdding(true)}>Add a feed</Button> : undefined}
      />

      {feeds.isError && <Notice tone="error">{feeds.error.message}</Notice>}
      {feeds.data?.length === 0 && (
        <Callout action={canManage ? <Button onClick={() => setAdding(true)}>Add your first feed</Button> : undefined}>
          <p className="font-medium">No feeds yet</p>
          <p className="mt-1 text-muted-foreground">
            Add the address your shop publishes its products at, or upload an export: CSV, XML, JSON or Excel. We read
            it and show you every column, before anything is translated.
          </p>
          {!canManage && <p className="mt-2 text-faint">The owner or an admin of the workspace adds feeds.</p>}
        </Callout>
      )}
      {feeds.data !== undefined && feeds.data.length > 0 && (
        <div className="overflow-hidden rounded-lg border-[0.5px] border-hairline bg-background">
          <div
            aria-hidden="true"
            className="hidden grid-cols-[32px_minmax(0,1.6fr)_110px_minmax(0,1fr)_120px_16px] gap-4 bg-subtle px-4 py-2.5 text-xs text-faint md:grid"
          >
            <span />
            <span>Feed</span>
            <span className="text-right">Products</span>
            <span>Status</span>
            <span>Last file</span>
            <span />
          </div>
          <ul>
            {feeds.data.map((feed) => (
              <FeedRow key={feed.id} feed={feed} />
            ))}
          </ul>
        </div>
      )}

      {adding && <AddFeedDialog onClose={() => setAdding(false)} />}
    </div>
  )
}

function FeedRow({ feed }: { feed: Feed }) {
  const latest = feed.latest_import
  const source = [feed.source === 'url' ? (feed.host ?? 'its URL') : 'Uploaded files']
  if (latest !== null && latest.format !== null) source.push(FORMAT[latest.format])
  if (latest !== null && latest.column_count !== null)
    source.push(`${latest.column_count.toLocaleString('en')} columns`)

  return (
    <li className="border-t-[0.5px] border-hairline first:border-t-0 md:first:border-t-[0.5px]">
      <Link
        to={`/app/feeds/${feed.id}`}
        className="grid grid-cols-[32px_minmax(0,1fr)_16px] items-center gap-x-4 gap-y-2 px-4 py-3.5 transition-colors duration-200 hover:bg-subtle md:grid-cols-[32px_minmax(0,1.6fr)_110px_minmax(0,1fr)_120px_16px]"
      >
        <Initials name={feed.name} />
        <span className="flex min-w-0 flex-col gap-0.5">
          <span className="truncate text-sm font-medium">{feed.name}</span>
          <span className="truncate font-mono text-[11px] text-faint">{source.join(' · ')}</span>
        </span>
        <span className="col-start-2 font-mono text-[13px] md:col-start-auto md:text-right">
          {latest?.row_count !== null && latest?.row_count !== undefined ? (
            <>
              {latest.row_count.toLocaleString('en')}
              <span className="text-faint md:sr-only"> products</span>
            </>
          ) : (
            <span className="text-faint">—</span>
          )}
        </span>
        <span className="col-start-2 md:col-start-auto">
          <FeedStateBadge state={feedState(feed)} />
        </span>
        <span className="col-start-2 text-xs text-faint md:col-start-auto">
          {latest !== null ? formatWhen(latest.created_at) : 'No file yet'}
        </span>
        <ChevronRight
          size={16}
          strokeWidth={1.5}
          className="col-start-3 row-start-1 text-faint md:col-start-auto md:row-start-auto"
          aria-hidden="true"
        />
      </Link>
    </li>
  )
}
