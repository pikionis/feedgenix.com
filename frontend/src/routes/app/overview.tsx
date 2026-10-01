import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { Card } from '~/components/ui/card'
import { UsageBar } from '~/components/ui/usage-bar'
import { feedsQuery } from '~/features/feeds/queries'
import { StatusBadge } from '~/features/feeds/StatusBadge'
import { workspaceQuery } from '~/features/workspace/queries'
import { useSession } from '~/lib/session'
import type { Route } from './+types/overview'

export const meta: Route.MetaFunction = () => [
  { title: 'Overview — FeedGenix' },
  { name: 'robots', content: 'noindex' },
]

export default function Overview() {
  const session = useSession()
  const workspace = useQuery(workspaceQuery)
  const feeds = useQuery(feedsQuery)
  const firstName = session.data?.name.split(' ')[0] ?? ''

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-medium tracking-tight">Welcome, {firstName}</h1>
        <p className="mt-1 text-muted-foreground">
          {feeds.data?.length === 0
            ? 'Start with a feed: the address your shop publishes its products at, or a file.'
            : 'Your feeds and your plan at a glance.'}
        </p>
      </div>
      <div className="grid max-w-4xl gap-6 md:grid-cols-2">
        {feeds.data !== undefined && (
          <Card className="flex flex-col gap-4">
            <div className="flex items-baseline justify-between">
              <h2 className="font-medium">Feeds</h2>
              <Link to="/app/feeds" className="text-sm text-primary hover:text-primary-hover">
                {feeds.data.length === 0 ? 'Add a feed' : 'All feeds'}
              </Link>
            </div>
            {feeds.data.length === 0 ? (
              <p className="text-sm text-muted-foreground">No feeds yet.</p>
            ) : (
              <ul className="flex flex-col gap-2 text-sm">
                {feeds.data.slice(0, 5).map((feed) => (
                  <li key={feed.id} className="flex items-center justify-between gap-3">
                    <Link to={`/app/feeds/${feed.id}`} className="truncate hover:text-primary">
                      {feed.name}
                    </Link>
                    {feed.latest_import !== null && <StatusBadge status={feed.latest_import.status} />}
                  </li>
                ))}
              </ul>
            )}
          </Card>
        )}
        {workspace.data !== undefined && (
          <Card className="flex flex-col gap-4">
            <div className="flex items-baseline justify-between">
              <h2 className="font-medium">{workspace.data.plan.name} plan</h2>
              <Link to="/app/settings#plan" className="text-sm text-primary hover:text-primary-hover">
                See plans
              </Link>
            </div>
            <UsageBar
              label="Feeds"
              used={workspace.data.entitlements.used.feeds}
              allowed={workspace.data.entitlements.limits.feeds}
            />
            <UsageBar
              label="Target languages"
              used={workspace.data.entitlements.used.languages}
              allowed={workspace.data.entitlements.limits.languages}
            />
            <p className="text-sm text-muted-foreground">
              {workspace.data.languages.length === 0 ? (
                <>
                  No target languages yet.{' '}
                  <Link to="/app/settings#languages" className="text-primary hover:text-primary-hover">
                    Choose them
                  </Link>
                </>
              ) : (
                workspace.data.languages.map((language) => language.name).join(' · ')
              )}
            </p>
          </Card>
        )}
      </div>
    </div>
  )
}
