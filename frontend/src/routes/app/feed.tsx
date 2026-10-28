import { useQuery, useQueryClient } from '@tanstack/react-query'
import { useState, type ReactNode } from 'react'
import { Link, useParams } from 'react-router'
import { Button } from '~/components/ui/button'
import { Callout } from '~/components/ui/callout'
import { Notice } from '~/components/ui/notice'
import { PageHeader } from '~/components/ui/page-header'
import { Rows, Section } from '~/components/ui/section'
import { DeleteFeedDialog } from '~/features/feeds/DeleteFeedDialog'
import { feedErrorMessage } from '~/features/feeds/errors'
import { ImportHistory } from '~/features/feeds/ImportHistory'
import { ImportProfile } from '~/features/feeds/ImportProfile'
import { formatWait, formatWhen } from '~/features/feeds/labels'
import { PipelineStages } from '~/features/feeds/PipelineStages'
import { feedQuery, feedsQuery, importQuery, isRunning, NotFound } from '~/features/feeds/queries'
import { ReadingOptionsForm } from '~/features/feeds/ReadingOptionsForm'
import { StatusBadge } from '~/features/feeds/StatusBadge'
import { UploadFileDialog } from '~/features/feeds/UploadFileDialog'
import { api } from '~/lib/api/client'
import type { Feed, FeedImport, ImportStatus } from '~/lib/api/types'
import { useSession } from '~/lib/session'
import type { Route } from './+types/feed'

export const meta: Route.MetaFunction = () => [{ title: 'Feed — FeedGenix' }, { name: 'robots', content: 'noindex' }]

const RUNNING: Partial<Record<ImportStatus, string>> = {
  queued: 'Waiting for its turn…',
  fetching: 'Downloading the feed…',
  reading: 'Reading the file…',
}

export default function FeedPage() {
  const params = useParams()
  const feedId = Number(params.feedId)
  const valid = Number.isInteger(feedId) && feedId > 0
  const feed = useQuery({ ...feedQuery(valid ? feedId : 0), enabled: valid })

  if (!valid || feed.error instanceof NotFound) {
    return (
      <div className="flex max-w-xl flex-col items-start gap-3 rounded-lg bg-surface p-5">
        <h1 className="font-medium">This feed does not exist</h1>
        <p className="text-sm text-muted-foreground">It may have been deleted, or it belongs to another workspace.</p>
        <Link to="/app/feeds" className="text-sm text-primary hover:text-primary-hover">
          All feeds
        </Link>
      </div>
    )
  }
  if (feed.isError) return <Notice tone="error">{feed.error.message}</Notice>
  if (feed.data === undefined) return <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />

  return <FeedView feed={feed.data} />
}

function FeedView({ feed }: { feed: Feed }) {
  const session = useSession()
  const role = session.data?.workspace?.role
  const canManage = role === 'owner' || role === 'admin'
  const [dialog, setDialog] = useState<'upload' | 'delete' | null>(null)
  const latest = feed.latest_import
  const running = isRunning(latest?.status)
  const latestFailed = latest !== null && latest.status === 'failed'
  const failedImport = useQuery({ ...importQuery(latest?.id ?? 0), enabled: latestFailed })
  const profile = useQuery({ ...importQuery(feed.profile_import_id ?? 0), enabled: feed.profile_import_id !== null })

  return (
    <div className="flex flex-col gap-8">
      <div className="flex flex-col gap-5">
        <PageHeader
          back={{ to: '/app/feeds', label: 'Feeds' }}
          title={feed.name}
          subtitle={feed.source === 'url' ? `Fetched from ${feed.host ?? 'its URL'}` : 'Fed by the files you upload'}
          actions={
            <>
              {feed.source === 'url' ? (
                <FetchButton feed={feed} disabled={running || feed.plan_paused} />
              ) : (
                <Button disabled={running || feed.plan_paused} onClick={() => setDialog('upload')}>
                  Upload a new file
                </Button>
              )}
              {canManage && (
                <Button variant="ghost" onClick={() => setDialog('delete')}>
                  Delete
                </Button>
              )}
            </>
          }
        />

        {feed.plan_paused && (
          <Notice tone="warn">
            This feed is paused by your plan: it takes no new files and no AI work, and its feed URLs keep serving the
            last build.{' '}
            <Link to="/app/billing/plans" className="text-primary hover:text-primary-hover">
              Upgrade, or delete another feed
            </Link>{' '}
            to use it again.
          </Notice>
        )}

        <div className="rounded-lg bg-surface px-5 py-4">
          <PipelineStages feed={feed} />
        </div>

        <NextSteps feed={feed} />

        {latest === null && feed.source === 'upload' && (
          <Callout action={<Button onClick={() => setDialog('upload')}>Upload a file</Button>}>
            <p className="text-muted-foreground">No file yet. Upload one and we read it straight away.</p>
          </Callout>
        )}
        {latest !== null && running && (
          <div className="flex items-center gap-3 rounded-lg bg-surface px-4 py-3" aria-live="polite">
            <StatusBadge status={latest.status} />
            <span className="text-sm">{RUNNING[latest.status]}</span>
          </div>
        )}
        {latest !== null && latest.status === 'unchanged' && (
          <Notice>
            The last {latest.origin === 'upload' ? 'upload' : 'fetch'} ({formatWhen(latest.created_at)}) brought the
            same file: nothing changed.
          </Notice>
        )}
        {latestFailed && latest.failure !== null && (
          <div className="flex flex-col gap-4 rounded-lg border-[0.5px] border-hairline p-4">
            <div className="flex flex-col gap-2">
              <div className="flex items-center gap-3">
                <StatusBadge status="failed" />
                <span className="text-sm text-faint">{formatWhen(latest.created_at)}</span>
              </div>
              <p role="alert">{latest.failure.message}</p>
              {latest.failure.retry_after_seconds !== null && (
                <p className="text-sm text-muted-foreground">
                  The source asked us to wait: try again in {formatWait(latest.failure.retry_after_seconds)}.
                </p>
              )}
              {feed.profile_import_id !== null && (
                <p className="text-sm text-muted-foreground">The profile below is from the last file that was read.</p>
              )}
            </div>
            {failedImport.data?.can_reread === true && (
              <ReadDifferently data={failedImport.data} open>
                Read this file again with other options, if it was not read right.
              </ReadDifferently>
            )}
          </div>
        )}
      </div>

      {profile.data !== undefined && (
        <Section
          title="What we read"
          aside={
            <span className="text-xs text-faint">
              {formatWhen(profile.data.finished_at ?? profile.data.created_at)}
            </span>
          }
        >
          <ImportProfile data={profile.data} />
          {profile.data.can_reread && !latestFailed && (
            <ReadDifferently data={profile.data}>
              Accents look wrong, or everything landed in one column? Read the file again with other options. The choice
              is kept for the next files.
            </ReadDifferently>
          )}
        </Section>
      )}

      <Section title="History">
        <ImportHistory feedId={feed.id} latest={latest} />
        {latest === null && <p className="text-sm text-faint">Nothing imported yet.</p>}
      </Section>

      {dialog === 'upload' && <UploadFileDialog feed={feed} onClose={() => setDialog(null)} />}
      {dialog === 'delete' && <DeleteFeedDialog feed={feed} onClose={() => setDialog(null)} />}
    </div>
  )
}

interface Step {
  name: string
  text: string
  action: string
  to: string
  primary: boolean
  live?: boolean
}

/** The mapping, the translation and the feed URLs: where each stands, and the way to its page. */
function NextSteps({ feed }: { feed: Feed }) {
  const steps: Step[] = []
  if (feed.profile_import_id !== null) {
    const approved = feed.mapping_status === 'approved'
    steps.push({
      name: 'Mapping',
      text: approved
        ? 'Approved: every column fills the right product field.'
        : 'Which column fills each product field. A draft is ready: review it, let the AI suggest the rest, approve.',
      action: approved ? 'Open the mapping' : 'Review the mapping',
      to: `/app/feeds/${feed.id}/mapping`,
      primary: !approved,
    })
  }
  if (feed.mapping_status === 'approved') steps.push(translationStep(feed))
  if (feed.mapping_status === 'approved' && feed.run !== null) {
    steps.push({
      name: 'Publish',
      text:
        feed.exports.published > 0
          ? `${feed.exports.published} ${feed.exports.published === 1 ? 'feed URL is' : 'feed URLs are'} live.`
          : 'Feed URLs for your sales channels: Google Merchant Center, or every field as CSV.',
      action: feed.exports.count > 0 ? 'Open the feed URLs' : 'Publish the feed',
      to: `/app/feeds/${feed.id}/publish`,
      primary: feed.exports.count === 0,
    })
  }
  if (steps.length === 0) return null

  return (
    <Rows aria-label="Steps">
      {steps.map((step) => (
        <li key={step.name} className="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 py-3.5">
          <div className="min-w-0">
            <h2 className="text-sm font-medium">{step.name}</h2>
            <p className="text-sm text-muted-foreground" aria-live={step.live === true ? 'polite' : undefined}>
              {step.text}
            </p>
          </div>
          <Button asChild size="sm" variant={step.primary ? 'primary' : 'secondary'}>
            <Link to={step.to}>{step.action}</Link>
          </Button>
        </li>
      ))}
    </Rows>
  )
}

/** Where the translation stands. */
function translationStep(feed: Feed): Step {
  const run = feed.run
  const [text, action, primary] = ((): [string, string, boolean] => {
    if (run === null)
      return ['Choose the texts and the languages, see the estimate, start.', 'Plan the translation', true]
    if (run.status === 'failed') return ['The last run failed.', 'See why', true]
    if (run.stage === 'sync') return ['Reading the products into the catalogue…', 'Open', false]
    if (run.stage === 'plan')
      return ['The products are read. Choose what to translate and into which languages.', 'Plan the translation', true]
    if (run.status === 'held') return ['Waiting: some texts cannot be translated right now.', 'See why', false]
    if (run.stage === 'translate') {
      return [
        `Translating: ${run.texts_done.toLocaleString('en')} of ${run.texts_planned.toLocaleString('en')} texts.`,
        'See the progress',
        false,
      ]
    }
    return ['Translated. Change the plan or add a language at any time.', 'Open the translation', false]
  })()

  return { name: 'Translation', text, action, to: `/app/feeds/${feed.id}/translation`, primary, live: true }
}

function ReadDifferently({ data, open, children }: { data: FeedImport; open?: boolean; children: ReactNode }) {
  return (
    <details open={open} className="group rounded-lg border-[0.5px] border-hairline px-4 py-3">
      <summary className="cursor-pointer text-sm font-medium">Read it differently</summary>
      <div className="mt-3 flex flex-col gap-4">
        <p className="text-sm text-muted-foreground">{children}</p>
        <ReadingOptionsForm data={data} />
      </div>
    </details>
  )
}

function FetchButton({ feed, disabled }: { feed: Feed; disabled: boolean }) {
  const client = useQueryClient()
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string>()

  const fetchNow = async () => {
    setBusy(true)
    setError(undefined)
    const { error: body, response } = await api.POST('/v1/feeds/{feed}/fetch', { params: { path: { feed: feed.id } } })
    setBusy(false)
    if (!response.ok) return setError(feedErrorMessage(response.status, body))
    await Promise.all([
      client.invalidateQueries({ queryKey: feedQuery(feed.id).queryKey, exact: true }),
      client.invalidateQueries({ queryKey: feedsQuery.queryKey, exact: true }),
    ])
  }

  return (
    <div className="flex flex-col items-end gap-2">
      <Button disabled={disabled || busy} onClick={() => void fetchNow()}>
        {busy ? 'Fetching…' : 'Fetch now'}
      </Button>
      {error !== undefined && (
        <Notice tone="error" className="max-w-sm">
          {error}
        </Notice>
      )}
    </div>
  )
}
