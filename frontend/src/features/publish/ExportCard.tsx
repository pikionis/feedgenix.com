import { useState } from 'react'
import { Button } from '~/components/ui/button'
import { Card } from '~/components/ui/card'
import { Dialog, DialogClose } from '~/components/ui/dialog'
import { Notice } from '~/components/ui/notice'
import { formatWhen } from '~/features/feeds/labels'
import type { ExportProfile } from '~/lib/api/types'
import type { ExportActions } from './actions'
import { buildSize, DIALECTS, gaps, LAYOUTS } from './labels'
import { isBuilding } from './queries'

/**
 * One export: its URLs to copy, the build they serve, what the last attempt found, the earlier
 * builds to go back to, and (for owners and admins) building, new URLs, pausing and deleting.
 */
export function ExportCard({
  profile,
  names,
  canManage,
  actions,
}: {
  profile: ExportProfile
  names: Record<string, string>
  canManage: boolean
  actions: ExportActions
}) {
  const [confirm, setConfirm] = useState<'rotate' | 'remove' | null>(null)
  const { published, latest } = profile
  const building = isBuilding(profile)
  const busy = actions.busy !== null && actions.busy.endsWith(`:${profile.id}`)
  const error = Object.entries(actions.errors).find(([key]) => key.endsWith(`:${profile.id}`))?.[1]
  const attempt = latest !== null && latest.id !== published?.id ? latest : null

  return (
    <Card className="flex flex-col gap-4">
      <div className="flex flex-wrap items-start justify-between gap-3">
        <div className="min-w-0">
          <h2 className="font-medium">{profile.name}</h2>
          <p className="text-sm text-muted-foreground">
            {profile.preset_label} · {DIALECTS[profile.dialect]} · {LAYOUTS[profile.layout]}
          </p>
        </div>
        <span className="rounded-full bg-surface px-3 py-1 text-sm" aria-live="polite">
          {profile.status === 'paused'
            ? 'Paused'
            : building
              ? 'Building…'
              : published !== null
                ? 'Live'
                : 'Not built yet'}
        </span>
      </div>

      <ul className="flex flex-col gap-2" aria-label={`${profile.name}: feed URLs`}>
        {profile.urls.map((url) => (
          <UrlRow
            key={url.file}
            label={url.locale === null ? 'All languages' : (names[url.locale] ?? url.locale)}
            url={url.url}
            gzUrl={url.gz_url}
          />
        ))}
      </ul>
      {profile.previous_urls_until !== null && (
        <Notice>
          The previous URLs keep working until {formatWhen(profile.previous_urls_until)}: switch your channels over.
        </Notice>
      )}

      {published === null ? (
        <p className="text-sm text-muted-foreground">
          Nothing is published yet. Until the first build, the URLs answer that the feed is not ready.
        </p>
      ) : (
        <div className="flex flex-col gap-2">
          <p className="text-sm">
            Serving the build of {formatWhen(published.published_at ?? published.created_at ?? '')}:{' '}
            {buildSize(published)}.
          </p>
          {published.report.left_out > 0 && (
            <Notice tone="warn">
              {published.report.left_out.toLocaleString('en')}{' '}
              {published.report.left_out === 1 ? 'product was' : 'products were'} left out: a code or price holds a tab
              or line break, which this format cannot carry unchanged.
              {published.report.examples[0] !== undefined &&
                ` For example ${published.report.examples[0].sku} (${published.report.examples[0].column}).`}
            </Notice>
          )}
          {gaps(published).length > 0 && (
            <ul className="flex flex-col gap-1 text-sm text-muted-foreground">
              {gaps(published).map((gap) => (
                <li key={`${gap.file}:${gap.column}`}>
                  {profile.preset_label} requires <span className="font-mono text-xs">{gap.column}</span>:{' '}
                  {gap.count.toLocaleString('en')} {gap.count === 1 ? 'product has' : 'products have'} none in{' '}
                  {gap.file}.
                </li>
              ))}
            </ul>
          )}
        </div>
      )}

      {attempt?.status === 'rejected' && (
        <Notice tone="warn">
          <span className="block">{attempt.failure_message}</span>
          {canManage && (
            <Button
              size="sm"
              variant="secondary"
              className="mt-2"
              disabled={busy}
              onClick={() => void actions.build(profile, true)}
            >
              Publish it anyway
            </Button>
          )}
        </Notice>
      )}
      {attempt?.status === 'failed' && (
        <Notice tone="error">{attempt.failure_message ?? 'The last build failed.'}</Notice>
      )}
      {error !== undefined && <Notice tone="error">{error}</Notice>}

      {profile.earlier.length > 0 && (
        <details>
          <summary className="cursor-pointer text-sm text-primary">Earlier builds</summary>
          <ul className="mt-2 flex flex-col gap-2">
            {profile.earlier.map((build) => (
              <li key={build.id} className="flex flex-wrap items-center justify-between gap-2 text-sm">
                <span>
                  {formatWhen(build.published_at ?? '')} · {build.rows.toLocaleString('en')} products
                </span>
                {canManage && (
                  <Button
                    size="sm"
                    variant="secondary"
                    disabled={busy}
                    onClick={() => void actions.publishEarlier(profile, build.id)}
                  >
                    Serve this one again
                  </Button>
                )}
              </li>
            ))}
          </ul>
        </details>
      )}

      {canManage && (
        <div className="flex flex-wrap gap-2 border-t-[0.5px] border-hairline pt-4">
          <Button
            size="sm"
            disabled={busy || building || profile.status === 'paused'}
            onClick={() => void actions.build(profile)}
          >
            Build now
          </Button>
          <Button
            size="sm"
            variant="secondary"
            disabled={busy}
            onClick={() => void actions.update(profile, { status: profile.status === 'paused' ? 'active' : 'paused' })}
          >
            {profile.status === 'paused' ? 'Resume' : 'Pause'}
          </Button>
          <Button size="sm" variant="secondary" disabled={busy} onClick={() => setConfirm('rotate')}>
            New URLs
          </Button>
          <Button size="sm" variant="ghost" disabled={busy} onClick={() => setConfirm('remove')}>
            Delete
          </Button>
        </div>
      )}

      {confirm !== null && (
        <Dialog
          open
          onOpenChange={(open) => !open && setConfirm(null)}
          title={confirm === 'rotate' ? 'New URLs?' : `Delete ${profile.name}?`}
          description={
            confirm === 'rotate'
              ? 'The new URLs work at once. The current ones keep working for 72 hours, then stop: switch your channels over.'
              : 'Its URLs stop working at once. Channels that pull them get nothing from then on.'
          }
        >
          <div className="flex justify-end gap-2">
            <DialogClose asChild>
              <Button type="button" variant="secondary">
                Cancel
              </Button>
            </DialogClose>
            <Button
              type="button"
              className={confirm === 'remove' ? 'bg-error hover:bg-error/90' : undefined}
              onClick={() => {
                const action = confirm
                setConfirm(null)
                void (action === 'rotate' ? actions.rotate(profile) : actions.remove(profile))
              }}
            >
              {confirm === 'rotate' ? 'Make new URLs' : 'Delete export'}
            </Button>
          </div>
        </Dialog>
      )}
    </Card>
  )
}

function UrlRow({ label, url, gzUrl }: { label: string; url: string; gzUrl: string }) {
  const [copied, setCopied] = useState(false)

  return (
    <li className="flex flex-col gap-1">
      <span className="text-xs text-muted-foreground">{label}</span>
      <div className="flex gap-2">
        <input
          readOnly
          aria-label={`${label}: feed URL`}
          value={url}
          onFocus={(event) => event.target.select()}
          className="h-9 min-w-0 flex-1 rounded-md border-[0.5px] border-hairline bg-subtle px-2 font-mono text-xs"
        />
        <Button
          size="sm"
          variant="secondary"
          className="h-9"
          onClick={() =>
            void navigator.clipboard.writeText(url).then(() => {
              setCopied(true)
              window.setTimeout(() => setCopied(false), 2000)
            })
          }
        >
          {copied ? 'Copied' : 'Copy'}
        </Button>
      </div>
      <a href={gzUrl} className="self-start text-xs text-primary hover:text-primary-hover">
        The same, compressed (.gz)
      </a>
    </li>
  )
}
