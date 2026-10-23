import { Check, X } from 'lucide-react'
import type { Feed } from '~/lib/api/types'
import { cn } from '~/lib/utils'

export type StageState = 'done' | 'active' | 'failed' | 'next' | 'soon'

export interface Stage {
  key: 'import' | 'analysis' | 'mapping' | 'plan' | 'translation' | 'publish'
  name: string
  state: StageState
  detail: string
}

/** What each stage does, shown while it has not run (or cannot run yet). */
const PURPOSE: Record<Stage['key'], string> = {
  import: 'Upload a file or fetch the URL',
  analysis: 'Columns, types and the product ID',
  mapping: 'AI proposes, you review',
  plan: 'Choose the fields to translate',
  translation: 'With quality checks',
  publish: 'A feed URL per language',
}

/**
 * The six stages of a feed and where this feed stands. Import and analysis exist today; mapping,
 * the translation plan, translation and publishing are built next, so they say "Coming soon"
 * instead of pretending to run.
 */
export function pipelineStages(feed: Feed): Stage[] {
  const latest = feed.latest_import
  const status = latest?.status
  const hasProfile = feed.profile_import_id !== null
  const failedNow = status === 'failed' && !hasProfile

  const importStage = ((): Omit<Stage, 'key' | 'name'> => {
    if (latest === null) return { state: 'next', detail: PURPOSE.import }
    // Short words: the progress card below the stages says it in full.
    if (status === 'queued') return { state: 'active', detail: 'Queued' }
    if (status === 'fetching') return { state: 'active', detail: 'Downloading' }
    if (failedNow && latest.origin === 'fetch') return { state: 'failed', detail: 'The download failed' }
    if (latest.origin === 'fetch') return { state: 'done', detail: `Downloaded from ${feed.host ?? 'the URL'}` }
    return { state: 'done', detail: latest.original_name ?? 'File received' }
  })()

  const analysisStage = ((): Omit<Stage, 'key' | 'name'> => {
    if (status === 'reading') return { state: 'active', detail: 'Reading' }
    if (failedNow && latest?.origin !== 'fetch') return { state: 'failed', detail: 'The file could not be read' }
    if (latest !== null && (status === 'profiled' || status === 'unchanged') && latest.row_count !== null) {
      return {
        state: 'done',
        detail: `${latest.row_count.toLocaleString('en')} rows · ${latest.column_count ?? 0} columns`,
      }
    }
    if (hasProfile) return { state: 'done', detail: 'Read from an earlier file' }
    return { state: 'soon', detail: PURPOSE.analysis }
  })()

  // Nothing after analysis exists yet: the first of them is next once there is a profile.
  const later = (key: Stage['key'], isNext: boolean): Omit<Stage, 'key' | 'name'> => ({
    state: isNext ? 'next' : 'soon',
    detail: PURPOSE[key],
  })

  return [
    { key: 'import', name: 'Import', ...importStage },
    { key: 'analysis', name: 'Analysis', ...analysisStage },
    { key: 'mapping', name: 'AI mapping', ...later('mapping', hasProfile) },
    { key: 'plan', name: 'Translation plan', ...later('plan', false) },
    { key: 'translation', name: 'Translation', ...later('translation', false) },
    { key: 'publish', name: 'Publish', ...later('publish', false) },
  ]
}

const AVAILABLE: ReadonlySet<Stage['key']> = new Set(['import', 'analysis'])

export function PipelineStages({ feed }: { feed: Feed }) {
  const stages = pipelineStages(feed)

  return (
    <ol aria-label="Pipeline" className="grid gap-4 sm:grid-cols-3 lg:grid-cols-6 lg:gap-3">
      {stages.map((stage, index) => (
        <li
          key={stage.key}
          aria-current={stage.state === 'active' ? 'step' : undefined}
          className="flex items-start gap-3 lg:flex-col lg:gap-2"
        >
          <Marker state={stage.state} number={index + 1} />
          <div className="min-w-0">
            <p className={cn('text-sm font-medium', stage.state === 'soon' && 'text-muted-foreground')}>{stage.name}</p>
            <p className="text-xs text-muted-foreground">{stage.detail}</p>
            {!AVAILABLE.has(stage.key) && (
              <p className="mt-1 text-xs text-faint">{stage.state === 'next' ? 'Next · coming soon' : 'Coming soon'}</p>
            )}
            {stage.state === 'failed' && <span className="sr-only">Failed</span>}
            {stage.state === 'done' && <span className="sr-only">Done</span>}
            {stage.state === 'active' && <span className="sr-only">In progress</span>}
          </div>
        </li>
      ))}
    </ol>
  )
}

function Marker({ state, number }: { state: StageState; number: number }) {
  const base = 'flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-medium'
  if (state === 'done') {
    return (
      <span className={cn(base, 'bg-success-bg text-success')} aria-hidden="true">
        <Check size={14} strokeWidth={2.25} />
      </span>
    )
  }
  if (state === 'failed') {
    return (
      <span className={cn(base, 'bg-error-bg text-error')} aria-hidden="true">
        <X size={14} strokeWidth={2.25} />
      </span>
    )
  }

  return (
    <span
      aria-hidden="true"
      className={cn(
        base,
        state === 'active' && 'animate-pulse bg-primary text-primary-foreground',
        state === 'next' && 'border border-primary text-primary',
        state === 'soon' && 'border-[0.5px] border-hairline text-faint',
      )}
    >
      {number}
    </span>
  )
}
