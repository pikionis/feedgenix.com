import { Button } from '~/components/ui/button'
import { Card } from '~/components/ui/card'
import { Notice } from '~/components/ui/notice'
import { UsageBar } from '~/components/ui/usage-bar'
import type { PipelineRun } from '~/lib/api/types'
import { texts } from './labels'

/**
 * Where the feed's run stands: reading the products, translating (with what was kept or came
 * from the memory), waiting, done, or failed with a way to try again.
 */
export function RunCard({
  run,
  canManage,
  busy,
  onReadAgain,
}: {
  run: PipelineRun | null
  canManage: boolean
  busy: boolean
  onReadAgain: () => void
}) {
  const readAgain = canManage && (
    <Button variant="secondary" disabled={busy} onClick={onReadAgain}>
      {busy ? 'Reading…' : 'Read the products again'}
    </Button>
  )

  if (run === null) {
    return (
      <Card className="flex flex-wrap items-center justify-between gap-4">
        <p className="text-sm text-muted-foreground">
          The products have not been read into the catalogue yet. They are read through the approved mapping.
        </p>
        {readAgain}
      </Card>
    )
  }
  if (run.status === 'failed') {
    return (
      <Card className="flex flex-wrap items-center justify-between gap-4">
        <p role="alert" className="text-sm">
          {run.failure_message ?? 'The last run failed.'}
        </p>
        {readAgain}
      </Card>
    )
  }
  if (run.stage === 'sync') {
    return (
      <Card>
        <p className="flex items-center gap-2 text-sm" aria-live="polite">
          <span className="size-1.5 animate-pulse rounded-full bg-primary" aria-hidden="true" />
          Reading the products into the catalogue…
        </p>
      </Card>
    )
  }

  const counts = run.counts
  const planned = counts.planned ?? 0
  const done = counts.texts_done ?? 0
  const failed = counts.texts_failed ?? 0
  const started = run.stage === 'translate' || (run.stage === 'done' && counts.planned !== undefined)

  return (
    <>
      {run.warnings.map((warning) => (
        <Notice key={warning.code} tone="warn">
          {warning.message}
        </Notice>
      ))}
      {started && (
        <Card className="flex flex-col gap-3" aria-live="polite">
          <h2 className="font-medium">
            {run.status === 'held' ? 'Waiting' : run.stage === 'done' ? 'Translated' : 'Translating…'}
          </h2>
          {planned > 0 && <UsageBar label="Translated with AI" used={done} allowed={planned} />}
          <p className="text-sm text-muted-foreground">
            {[
              (counts.kept ?? 0) > 0 && `${texts(counts.kept ?? 0)} kept as they are`,
              (counts.memory ?? 0) > 0 && `${texts(counts.memory ?? 0)} from the translation memory, at no cost`,
              planned === 0 && 'Nothing needed AI',
            ]
              .filter(Boolean)
              .join(' · ')}
          </p>
          {failed > 0 && (
            <Notice tone="warn">
              {texts(failed)} could not be translated and stay empty. Start again to try them once more.
            </Notice>
          )}
        </Card>
      )}
    </>
  )
}
