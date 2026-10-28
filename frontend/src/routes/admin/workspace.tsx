import { useQuery } from '@tanstack/react-query'
import { Link, useParams } from 'react-router'
import { Notice } from '~/components/ui/notice'
import { PageHeader } from '~/components/ui/page-header'
import { Rows, Section } from '~/components/ui/section'
import { StatCard } from '~/components/ui/stat-card'
import { Table, TD, TH, THead, TR } from '~/components/ui/table'
import { adminWorkspaceQuery } from '~/features/admin/queries'
import { feedState, FeedStateBadge } from '~/features/feeds/FeedState'
import { formatWhen } from '~/features/feeds/labels'
import { NotFound } from '~/features/feeds/queries'
import type { AdminWorkspace } from '~/lib/api/types'
import type { Route } from './+types/workspace'

export const meta: Route.MetaFunction = () => [
  { title: 'Workspace — FeedGenix staff' },
  { name: 'robots', content: 'noindex' },
]

// The reasons RunProgress names (Domain/Translation/RunProgress.php), in staff words.
const HOLDS: Record<string, string> = {
  credits: 'Not enough credits',
  allowance: 'Over the period’s products',
  ai_disabled: 'AI switched off',
  unpriced_model: 'No priced model',
  provider_unavailable: 'Provider unavailable',
  ai_paused: 'AI paused for the workspace',
  workspace_frozen: 'Workspace frozen',
  feed_paused: 'Feed paused by the plan',
  language_paused: 'Language paused by the plan',
}

const KINDS: Record<string, string> = {
  grant: 'Grant',
  purchase: 'Purchase',
  ai_usage: 'AI work',
  refund: 'Refund',
  adjustment: 'Adjustment',
}

const back = { to: '/admin/workspaces', label: 'Workspaces' }

const limit = (value: number | null) => (value === null ? 'unlimited' : value.toLocaleString('en'))

export default function AdminWorkspacePage() {
  const params = useParams()
  const id = Number(params.workspaceId)
  const valid = Number.isInteger(id) && id > 0
  const workspace = useQuery({ ...adminWorkspaceQuery(valid ? id : 0), enabled: valid })

  if (!valid || workspace.error instanceof NotFound) {
    return (
      <div className="flex flex-col gap-5">
        <PageHeader back={back} title="This workspace does not exist" />
      </div>
    )
  }
  if (workspace.isError) return <Notice tone="error">{workspace.error.message}</Notice>
  if (workspace.data === undefined) return <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />

  return <WorkspaceView data={workspace.data} />
}

function WorkspaceView({ data }: { data: AdminWorkspace }) {
  const { workspace, plan } = data
  const marks = [workspace.internal && 'Internal', workspace.frozen && 'Frozen', workspace.ai_paused && 'AI paused']
    .filter((mark): mark is string => typeof mark === 'string')
    .join(' · ')

  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-col gap-5">
        <PageHeader
          back={back}
          title={workspace.name}
          subtitle={[
            `#${workspace.id} · ${workspace.slug}`,
            `${workspace.plan_name}${workspace.plan === 'free' ? '' : workspace.interval === 'year' ? ', yearly' : ', monthly'}`,
            workspace.created_at === null ? null : `since ${formatWhen(workspace.created_at)}`,
            marks === '' ? null : marks,
          ]
            .filter(Boolean)
            .join(' · ')}
        />
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <StatCard
            label="Feeds"
            value={workspace.feeds}
            detail={`of ${limit(plan.limits.feeds)}${workspace.feeds_paused > 0 ? ` · ${workspace.feeds_paused} paused` : ''}`}
          />
          <StatCard label="Languages" value={workspace.languages} detail={`of ${limit(plan.limits.languages)}`} />
          <StatCard
            label="Products this period"
            value={workspace.products_this_period.toLocaleString('en')}
            detail={`of ${limit(plan.limits.products)}`}
          />
          <StatCard
            label="Credits available"
            value={data.credits.available.toLocaleString('en')}
            detail={data.credits.reserved > 0 ? `${data.credits.reserved.toLocaleString('en')} held` : 'none held'}
          />
          <StatCard
            label="AI, 30 days"
            value={workspace.ai_credits_30d.toLocaleString('en')}
            detail="credits charged"
          />
        </div>
      </div>

      <Section
        title="Members"
        description={workspace.owner?.verified === false ? 'The owner has not verified the address yet.' : undefined}
      >
        <Table aria-label="Members">
          <THead>
            <tr>
              <TH>Name</TH>
              <TH>Email</TH>
              <TH>Role</TH>
            </tr>
          </THead>
          <tbody>
            {data.members.map((member) => (
              <TR key={member.id}>
                <TD>
                  {member.name}
                  {member.staff && <span className="text-faint"> · staff</span>}
                </TD>
                <TD>
                  {member.email}
                  {!member.verified && <span className="block text-xs text-warn">not verified</span>}
                </TD>
                <TD className="capitalize">{member.role}</TD>
              </TR>
            ))}
          </tbody>
        </Table>
      </Section>

      <Section
        title="Feeds"
        description={
          data.languages.length === 0
            ? 'No target languages.'
            : `Into ${data.languages.map((language) => `${language.code}${language.paused ? ' (paused)' : ''}`).join(', ')}.`
        }
      >
        {data.feeds.length === 0 ? (
          <p className="text-sm text-muted-foreground">No feeds yet.</p>
        ) : (
          <Rows aria-label="Feeds">
            {data.feeds.map((feed) => (
              <li key={feed.id} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                <span className="flex min-w-0 flex-col">
                  <span className="truncate text-sm font-medium">{feed.name}</span>
                  <span className="truncate font-mono text-[11px] text-faint">
                    #{feed.id} · {feed.source === 'url' ? (feed.host ?? 'URL') : 'uploads'}
                    {feed.latest_import?.row_count != null &&
                      ` · ${feed.latest_import.row_count.toLocaleString('en')} products`}
                  </span>
                </span>
                <FeedStateBadge state={feedState(feed)} />
              </li>
            ))}
          </Rows>
        )}
      </Section>

      {data.held.length > 0 && (
        <Section title="Work that waits">
          <Table aria-label="Work that waits">
            <THead>
              <tr>
                <TH>Why</TH>
                <TH numeric>Batches</TH>
                <TH numeric>Feeds</TH>
              </tr>
            </THead>
            <tbody>
              {data.held.map((held) => (
                <TR key={held.reason}>
                  <TD>{HOLDS[held.reason] ?? held.reason}</TD>
                  <TD numeric>{held.units.toLocaleString('en')}</TD>
                  <TD numeric>{held.feeds.toLocaleString('en')}</TD>
                </TR>
              ))}
            </tbody>
          </Table>
        </Section>
      )}

      <Section title="AI, last 30 days" description="Calls, tokens and the credits charged for them.">
        {data.ai_usage_30d.length === 0 ? (
          <p className="text-sm text-muted-foreground">No AI calls in the last 30 days.</p>
        ) : (
          <Table aria-label="AI, last 30 days">
            <THead>
              <tr>
                <TH>For</TH>
                <TH numeric>Calls</TH>
                <TH numeric>Failed</TH>
                <TH numeric>Tokens in</TH>
                <TH numeric>Tokens out</TH>
                <TH numeric>Thinking</TH>
                <TH numeric>Credits</TH>
              </tr>
            </THead>
            <tbody>
              {data.ai_usage_30d.map((usage) => (
                <TR key={usage.purpose}>
                  <TD className="capitalize">{usage.purpose}</TD>
                  <TD numeric>{usage.calls.toLocaleString('en')}</TD>
                  <TD numeric className={usage.failed > 0 ? 'text-warn' : undefined}>
                    {usage.failed.toLocaleString('en')}
                  </TD>
                  <TD numeric>{usage.input_tokens.toLocaleString('en')}</TD>
                  <TD numeric>{usage.output_tokens.toLocaleString('en')}</TD>
                  <TD numeric>{usage.thinking_tokens.toLocaleString('en')}</TD>
                  <TD numeric>{usage.credits.toLocaleString('en')}</TD>
                </TR>
              ))}
            </tbody>
          </Table>
        )}
      </Section>

      <Section title="Credits" description="The latest movements of the wallet.">
        {data.credits.transactions.length === 0 ? (
          <p className="text-sm text-muted-foreground">No credits yet.</p>
        ) : (
          <Table aria-label="Credits">
            <THead>
              <tr>
                <TH>When</TH>
                <TH>What</TH>
                <TH numeric>Credits</TH>
                <TH numeric>Balance after</TH>
              </tr>
            </THead>
            <tbody>
              {data.credits.transactions.map((transaction) => (
                <TR key={transaction.id}>
                  <TD className="text-xs whitespace-nowrap text-faint">{formatWhen(transaction.created_at)}</TD>
                  <TD>
                    {KINDS[transaction.kind] ?? transaction.kind}
                    {transaction.note !== null && <span className="text-muted-foreground"> · {transaction.note}</span>}
                  </TD>
                  <TD numeric>
                    {transaction.credits > 0 ? '+' : ''}
                    {transaction.credits.toLocaleString('en')}
                  </TD>
                  <TD numeric>{transaction.balance_after.toLocaleString('en')}</TD>
                </TR>
              ))}
            </tbody>
          </Table>
        )}
      </Section>

      <Section title="Notices" description="What the workspace was told, newest first.">
        {data.notices.length === 0 ? (
          <p className="text-sm text-muted-foreground">Nothing yet.</p>
        ) : (
          <Rows aria-label="Notices">
            {data.notices.map((notice) => (
              <li key={notice.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-sm">
                <span>
                  {notice.title}
                  {!notice.read && <span className="text-faint"> · unread</span>}
                </span>
                <span className="text-xs text-faint">{formatWhen(notice.created_at)}</span>
              </li>
            ))}
          </Rows>
        )}
      </Section>

      <Section
        title="Staff here"
        aside={
          <Link to="/admin/audit" className="text-sm text-primary hover:text-primary-hover">
            The whole trail
          </Link>
        }
      >
        <Rows aria-label="Staff here">
          {data.audit.map((line) => (
            <li key={line.id} className="flex flex-wrap items-center justify-between gap-2 px-4 py-2.5 text-sm">
              <span className="min-w-0 truncate">
                {line.user_email} <span className="text-muted-foreground">· {line.action}</span>
              </span>
              <span className="text-xs text-faint">{formatWhen(line.created_at)}</span>
            </li>
          ))}
        </Rows>
      </Section>
    </div>
  )
}
