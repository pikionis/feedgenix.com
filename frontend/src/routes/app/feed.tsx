import { useQuery, useQueryClient } from '@tanstack/react-query'
import { ArrowLeft } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { Link, useParams } from 'react-router'
import { Button } from '~/components/ui/button'
import { Card } from '~/components/ui/card'
import { Notice } from '~/components/ui/notice'
import { DeleteFeedDialog } from '~/features/feeds/DeleteFeedDialog'
import { feedErrorMessage } from '~/features/feeds/errors'
import { ImportHistory } from '~/features/feeds/ImportHistory'
import { ImportProfile } from '~/features/feeds/ImportProfile'
import { formatWait, formatWhen } from '~/features/feeds/labels'
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
      <Card className="flex max-w-xl flex-col items-start gap-3">
        <h1 className="font-medium">This feed does not exist</h1>
        <p className="text-sm text-muted-foreground">It may have been deleted, or it belongs to another workspace.</p>
        <Link to="/app/feeds" className="text-sm text-primary hover:text-primary-hover">
          All feeds
        </Link>
      </Card>
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
    <div className="flex flex-col gap-6">
      <div className="flex flex-col gap-3">
        <Link
          to="/app/feeds"
          className="inline-flex items-center gap-1 self-start text-sm text-muted-foreground hover:text-foreground"
        >
          <ArrowLeft size={14} strokeWidth={1.5} aria-hidden="true" /> Feeds
        </Link>
        <div className="flex flex-wrap items-start justify-between gap-4">
          <div className="min-w-0">
            <h1 className="truncate text-2xl font-medium tracking-tight">{feed.name}</h1>
            <p className="mt-1 text-muted-foreground">
              {feed.source === 'url' ? `Fetched from ${feed.host ?? 'its URL'}` : 'Fed by the files you upload'}
            </p>
          </div>
          <div className="flex flex-wrap gap-2">
            {feed.source === 'url' ? (
              <FetchButton feed={feed} disabled={running} />
            ) : (
              <Button disabled={running} onClick={() => setDialog('upload')}>
                Upload a new file
              </Button>
            )}
            {canManage && (
              <Button variant="ghost" onClick={() => setDialog('delete')}>
                Delete
              </Button>
            )}
          </div>
        </div>
      </div>

      {latest === null && feed.source === 'upload' && (
        <Card className="flex flex-col items-start gap-3">
          <p className="text-sm text-muted-foreground">No file yet. Upload one and we read it straight away.</p>
          <Button onClick={() => setDialog('upload')}>Upload a file</Button>
        </Card>
      )}
      {latest !== null && running && (
        <Card className="flex items-center gap-3" aria-live="polite">
          <StatusBadge status={latest.status} />
          <span className="text-sm">{RUNNING[latest.status]}</span>
        </Card>
      )}
      {latest !== null && latest.status === 'unchanged' && (
        <Notice>
          The last {latest.origin === 'upload' ? 'upload' : 'fetch'} ({formatWhen(latest.created_at)}) brought the same
          file: nothing changed.
        </Notice>
      )}
      {latestFailed && latest.failure !== null && (
        <Card className="flex flex-col gap-4">
          <div className="flex flex-col gap-2">
            <div className="flex items-center gap-3">
              <StatusBadge status="failed" />
              <span className="text-sm text-muted-foreground">{formatWhen(latest.created_at)}</span>
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
        </Card>
      )}

      {profile.data !== undefined && (
        <Card className="flex flex-col gap-6">
          <div className="flex flex-wrap items-baseline justify-between gap-2">
            <h2 className="font-medium">What we read</h2>
            <span className="text-sm text-faint">
              {formatWhen(profile.data.finished_at ?? profile.data.created_at)}
            </span>
          </div>
          <ImportProfile data={profile.data} />
          {profile.data.can_reread && !latestFailed && (
            <ReadDifferently data={profile.data}>
              Accents look wrong, or everything landed in one column? Read the file again with other options. The choice
              is kept for the next files.
            </ReadDifferently>
          )}
        </Card>
      )}

      <Card className="flex flex-col gap-3">
        <h2 className="font-medium">History</h2>
        <ImportHistory feedId={feed.id} latest={latest} />
        {latest === null && <p className="text-sm text-faint">Nothing imported yet.</p>}
      </Card>

      {dialog === 'upload' && <UploadFileDialog feed={feed} onClose={() => setDialog(null)} />}
      {dialog === 'delete' && <DeleteFeedDialog feed={feed} onClose={() => setDialog(null)} />}
    </div>
  )
}

function ReadDifferently({ data, open, children }: { data: FeedImport; open?: boolean; children: ReactNode }) {
  return (
    <details open={open} className="group rounded-md bg-subtle p-4">
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
