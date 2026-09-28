import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { Card } from '~/components/ui/card'
import { UsageBar } from '~/components/ui/usage-bar'
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
  const firstName = session.data?.name.split(' ')[0] ?? ''

  return (
    <div className="flex flex-col gap-6">
      <div>
        <h1 className="text-2xl font-medium tracking-tight">Welcome, {firstName}</h1>
        <p className="mt-1 text-muted-foreground">Your feeds will appear here once you connect the first one.</p>
      </div>
      {workspace.data !== undefined && (
        <Card className="flex max-w-xl flex-col gap-4">
          <div className="flex items-baseline justify-between">
            <h2 className="font-medium">{workspace.data.plan.name} plan</h2>
            <Link to="/app/settings#plan" className="text-sm text-primary hover:text-primary-hover">
              See plans
            </Link>
          </div>
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
  )
}
