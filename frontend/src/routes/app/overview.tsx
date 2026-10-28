import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { Button } from '~/components/ui/button'
import { Card } from '~/components/ui/card'
import { LanguagePill } from '~/components/ui/language-pill'
import { PageHeader } from '~/components/ui/page-header'
import { UsageBar } from '~/components/ui/usage-bar'
import { BlockDot, Highlight, Mosaic, SECTION_TONES, type Block, type BlockTone } from '~/components/ui/mosaic'
import { feedsQuery } from '~/features/feeds/queries'
import { feedState, FeedStateBadge } from '~/features/feeds/FeedState'
import { workspaceQuery } from '~/features/workspace/queries'
import { useSession } from '~/lib/session'
import { cn } from '~/lib/utils'
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
  const role = session.data?.workspace?.role
  const canManage = role === 'owner' || role === 'admin'
  const products = (feeds.data ?? []).reduce((sum, feed) => sum + (feed.latest_import?.row_count ?? 0), 0)
  const live = (feeds.data ?? []).reduce((sum, feed) => sum + feed.exports.published, 0)
  const empty = feeds.data?.length === 0

  return (
    <div className="flex flex-col gap-6">
      <PageHeader
        title={
          <>
            Welcome, <Highlight tone={SECTION_TONES.overview}>{firstName}</Highlight>
          </>
        }
        subtitle={
          empty
            ? 'Start with a feed: the address your shop publishes its products at, or a file.'
            : 'Your feeds and your plan at a glance.'
        }
        actions={
          canManage && !empty && feeds.data !== undefined ? (
            <Button asChild>
              <Link to="/app/feeds">Add a feed</Link>
            </Button>
          ) : undefined
        }
      />

      {feeds.data !== undefined && !empty && (
        <dl className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
          <Stat
            label="Feeds"
            tone="emerald"
            detail={
              workspace.data?.entitlements.limits.feeds === null || workspace.data === undefined
                ? undefined
                : `of ${workspace.data.entitlements.limits.feeds} on your plan`
            }
          >
            {feeds.data.length.toLocaleString('en')}
          </Stat>
          <Stat
            label="Products in the latest files"
            tone="cyan"
            detail={`${(workspace.data?.entitlements.used.products ?? 0).toLocaleString('en')} counted this period`}
          >
            {products.toLocaleString('en')}
          </Stat>
          <Stat label="Target languages" tone="pink">
            {workspace.data === undefined || workspace.data.languages.length === 0 ? (
              '0'
            ) : (
              <span className="flex flex-wrap gap-1 pt-1">
                {workspace.data.languages.map((language) => (
                  <LanguagePill key={language.code} code={language.code} name={language.name} />
                ))}
              </span>
            )}
          </Stat>
          <Stat
            label="Feed URLs live"
            tone="amber"
            detail={live > 0 ? 'Pulled by your channels' : 'Publish a feed to get one'}
          >
            {live.toLocaleString('en')}
          </Stat>
        </dl>
      )}

      {empty && canManage && (
        <Card className="flex flex-col gap-1">
          <h2 className="mb-3 font-medium">Get your first feed live</h2>
          <ol className="flex flex-col">
            <Step number={1} title="Choose a target language" detail="The languages your feeds are translated into.">
              <Link to="/app/settings#languages" className="text-sm text-primary hover:text-primary-hover">
                Choose it
              </Link>
            </Step>
            <Step
              number={2}
              title="Add a feed"
              detail="A URL or a file: CSV, XML, JSON or Excel. We show you every column before anything is translated."
            >
              <Button asChild size="sm">
                <Link to="/app/feeds">Add a feed</Link>
              </Button>
            </Step>
            <Step
              number={3}
              title="Map, translate, publish"
              detail="Review the AI mapping, see the estimate, get a feed URL per language."
            />
          </ol>
        </Card>
      )}

      <div className="grid gap-6 lg:grid-cols-2">
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
              <ul className="flex flex-col text-sm">
                {feeds.data.slice(0, 5).map((feed) => (
                  <li
                    key={feed.id}
                    className="flex items-center justify-between gap-3 border-t-[0.5px] border-hairline py-3 first:border-t-0 first:pt-0"
                  >
                    <Link to={`/app/feeds/${feed.id}`} className="truncate font-medium hover:text-primary">
                      {feed.name}
                    </Link>
                    <FeedStateBadge state={feedState(feed)} />
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
              <Link to="/app/billing/plans" className="text-sm text-primary hover:text-primary-hover">
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

      <Card className="relative flex flex-col gap-2 overflow-hidden border-transparent bg-warm sm:pr-44">
        <Mosaic blocks={GOLDEN_BLOCKS} className="hidden sm:block" />
        <h2 className="font-medium">
          The <Highlight tone="amber">Golden Rule</Highlight>
        </h2>
        <p className="text-sm leading-relaxed text-muted-foreground">
          SKUs, GTINs, prices, currency, stock, weights and dimensions pass through byte-identical. They never reach a
          prompt. When the AI is unsure, a field stays empty rather than wrong.
        </p>
      </Card>
    </div>
  )
}

// The Golden Rule card's right edge (text stops 11rem short of it from sm).
const GOLDEN_BLOCKS: readonly Block[] = [
  { tone: 'emerald', top: -14, right: -20, width: 110, height: 52 },
  { tone: 'cyan', top: 24, right: 104, width: 34, height: 20 },
  { tone: 'pink', bottom: 18, right: 22, width: 58, height: 30 },
  { tone: 'ink', bottom: -8, right: 92, width: 22, height: 22 },
  { tone: 'amber', bottom: 30, right: -10, width: 26, height: 26 },
]

const STEP_TONES = ['bg-block-pink', 'bg-block-cyan', 'bg-block-emerald'] as const

/** The app kit's stat card: Mist, 10px corners, no border; label, number, one line under it. */
function Stat({
  label,
  detail,
  tone,
  children,
}: {
  label: string
  detail?: string
  tone: BlockTone
  children: React.ReactNode
}) {
  return (
    <div className="relative flex flex-col rounded-lg bg-surface px-4 py-3.5">
      <BlockDot tone={tone} className="absolute top-3.5 right-4 h-2.5 w-5" />
      <dt className="pr-7 text-xs text-muted-foreground">{label}</dt>
      <dd className="my-1.5 text-[26px] leading-none font-medium tracking-[-0.01em]">{children}</dd>
      {detail !== undefined && <dd className="text-xs text-muted-foreground">{detail}</dd>}
    </div>
  )
}

function Step({
  number,
  title,
  detail,
  children,
}: {
  number: number
  title: string
  detail: string
  children?: React.ReactNode
}) {
  return (
    <li className="flex flex-wrap items-center gap-4 border-t-[0.5px] border-hairline py-3.5">
      <span
        aria-hidden="true"
        className={cn(
          'flex size-7 items-center justify-center rounded-[4px] font-mono text-xs font-medium text-block-ink',
          STEP_TONES[(number - 1) % STEP_TONES.length],
        )}
      >
        {number}
      </span>
      <div className="min-w-[220px] flex-1">
        <p className="font-medium">{title}</p>
        <p className="text-sm text-muted-foreground">{detail}</p>
      </div>
      {children}
    </li>
  )
}
