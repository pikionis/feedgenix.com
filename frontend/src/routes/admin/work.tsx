import { useQuery } from '@tanstack/react-query'
import { Link } from 'react-router'
import { Notice } from '~/components/ui/notice'
import { PageHeader } from '~/components/ui/page-header'
import { Section } from '~/components/ui/section'
import { StatCard } from '~/components/ui/stat-card'
import { Table, TD, TH, THead, TR } from '~/components/ui/table'
import { holdLabel } from '~/features/admin/labels'
import { adminWorkQuery } from '~/features/admin/queries'
import { FeedStateBadge, type FeedTone } from '~/features/feeds/FeedState'
import { formatWhen } from '~/features/feeds/labels'
import type { AdminWork } from '~/lib/api/types'
import type { Route } from './+types/work'

export const meta: Route.MetaFunction = () => [
  { title: 'Work — FeedGenix staff' },
  { name: 'robots', content: 'noindex' },
]

type Run = AdminWork['runs'][number]

function runState(run: Run): { label: string; tone: FeedTone } {
  if (run.stuck) return { label: `Stuck ${run.status === 'queued' ? 'in the queue' : 'running'}`, tone: 'error' }
  if (run.status === 'failed') return { label: 'Failed', tone: 'error' }

  return { label: 'Waiting', tone: 'warn' }
}

/** What does not move, across every workspace. It follows on its own every 30 seconds. */
export default function AdminWorkPage() {
  const work = useQuery(adminWorkQuery)

  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-col gap-5">
        <PageHeader
          title="Work"
          subtitle="What does not move, in every workspace: runs that failed in the last 7 days, wait, or show no change for 30 minutes."
        />
        {work.isError && <Notice tone="error">{work.error.message}</Notice>}
        {work.data !== undefined && <Figures data={work.data} />}
      </div>

      {work.data !== undefined && (
        <>
          <Section title="Runs" description="Newest change first, at most 50.">
            {work.data.runs.length === 0 ? (
              <p className="rounded-lg border-[0.5px] border-hairline px-4 py-6 text-center text-sm text-muted-foreground">
                Every run moves.
              </p>
            ) : (
              <Table aria-label="Runs" className="min-w-[760px]">
                <THead>
                  <tr>
                    <TH>Workspace</TH>
                    <TH>Feed</TH>
                    <TH>Stage</TH>
                    <TH>State</TH>
                    <TH>Last change</TH>
                  </tr>
                </THead>
                <tbody>
                  {work.data.runs.map((run) => (
                    <TR key={run.id}>
                      <TD>
                        <Link
                          to={`/admin/workspaces/${run.tenant_id}`}
                          className="text-primary hover:text-primary-hover"
                        >
                          {run.workspace}
                        </Link>
                      </TD>
                      <TD>
                        {run.feed}
                        {run.failure_message !== null && (
                          <span className="block text-xs text-muted-foreground">{run.failure_message}</span>
                        )}
                      </TD>
                      <TD className="capitalize">{run.stage}</TD>
                      <TD>
                        <FeedStateBadge state={runState(run)} />
                      </TD>
                      <TD className="text-xs whitespace-nowrap text-faint">{formatWhen(run.updated_at)}</TD>
                    </TR>
                  ))}
                </tbody>
              </Table>
            )}
          </Section>

          {work.data.held.length > 0 && (
            <Section title="Batches that wait" description="By reason. Most resume on their own when the cause clears.">
              <Table aria-label="Batches that wait">
                <THead>
                  <tr>
                    <TH>Why</TH>
                    <TH numeric>Batches</TH>
                    <TH numeric>Workspaces</TH>
                  </tr>
                </THead>
                <tbody>
                  {work.data.held.map((held) => (
                    <TR key={held.reason}>
                      <TD>{holdLabel(held.reason)}</TD>
                      <TD numeric>{held.units.toLocaleString('en')}</TD>
                      <TD numeric>{held.workspaces.toLocaleString('en')}</TD>
                    </TR>
                  ))}
                </tbody>
              </Table>
            </Section>
          )}

          <Section title="Failed jobs" description="The latest ten; the full trace stays on the server.">
            {work.data.failed_jobs.length === 0 ? (
              <p className="text-sm text-muted-foreground">None.</p>
            ) : (
              <Table aria-label="Failed jobs">
                <THead>
                  <tr>
                    <TH>When</TH>
                    <TH>Job</TH>
                    <TH>Error</TH>
                  </tr>
                </THead>
                <tbody>
                  {work.data.failed_jobs.map((job) => (
                    <TR key={job.id}>
                      <TD className="text-xs whitespace-nowrap text-faint">{formatWhen(job.failed_at)}</TD>
                      <TD className="font-mono text-xs">{job.job}</TD>
                      <TD className="font-mono text-xs break-all text-muted-foreground">{job.error}</TD>
                    </TR>
                  ))}
                </tbody>
              </Table>
            )}
          </Section>
        </>
      )}
    </div>
  )
}

function Figures({ data }: { data: AdminWork }) {
  const held = data.held.reduce((sum, row) => sum + row.units, 0)

  return (
    <div className="flex flex-col gap-3">
      {!data.reconciler.running && (
        <Notice tone="error">
          {data.reconciler.last_at === null
            ? 'The reconciler has not run since the cache was last emptied. Check the scheduler on the server.'
            : `The reconciler last ran ${formatWhen(data.reconciler.last_at)}: nothing moves forward until it runs again. Check the scheduler on the server.`}
        </Notice>
      )}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard
          label="Reconciler"
          value={data.reconciler.running ? 'Running' : 'Silent'}
          detail={
            data.reconciler.last_at === null ? 'No pass seen' : `Last pass ${formatWhen(data.reconciler.last_at)}`
          }
        />
        <StatCard label="Jobs in the queue" value={data.queue.pending.toLocaleString('en')} />
        <StatCard label="Failed jobs, 24 hours" value={data.queue.failed_24h.toLocaleString('en')} />
        <StatCard label="Batches that wait" value={held.toLocaleString('en')} />
      </div>
    </div>
  )
}
