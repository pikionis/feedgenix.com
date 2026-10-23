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

/** The six stages of a feed and where this feed stands. */
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

  const mappingStage = ((): Omit<Stage, 'key' | 'name'> => {
    if (!hasProfile) return { state: 'soon', detail: PURPOSE.mapping }
    if (feed.mapping_status === 'approved') return { state: 'done', detail: 'Approved' }
    if (feed.mapping_status === 'draft') return { state: 'active', detail: 'Draft: review it' }
    return { state: 'next', detail: 'A draft is ready to review' }
  })()

  const run = feed.mapping_status === 'approved' ? feed.run : null
  const translating = run !== null && (run.stage === 'translate' || run.stage === 'export' || run.stage === 'done')

  const planStage = ((): Omit<Stage, 'key' | 'name'> => {
    if (feed.mapping_status !== 'approved') return { state: 'soon', detail: PURPOSE.plan }
    if (run === null) return { state: 'next', detail: PURPOSE.plan }
    if (run.stage === 'sync' && run.status === 'failed')
      return { state: 'failed', detail: 'Reading the products failed' }
    if (run.stage === 'sync') return { state: 'active', detail: 'Reading the products' }
    if (run.stage === 'plan') return { state: 'active', detail: 'Choose and start' }
    return { state: 'done', detail: 'Started' }
  })()

  const translationStage = ((): Omit<Stage, 'key' | 'name'> => {
    if (run === null || !translating) return { state: 'soon', detail: PURPOSE.translation }
    if (run.status === 'held') return { state: 'active', detail: 'Waiting' }
    if (run.stage === 'translate') {
      return {
        state: 'active',
        detail: `${run.texts_done.toLocaleString('en')} of ${run.texts_planned.toLocaleString('en')} texts`,
      }
    }
    return {
      state: 'done',
      detail: run.texts_planned > 0 ? `${run.texts_planned.toLocaleString('en')} texts` : 'Up to date',
    }
  })()

  // Publishing needs products in the catalogue; it does not wait for the translation.
  const publishStage = ((): Omit<Stage, 'key' | 'name'> => {
    if (feed.exports.published > 0) {
      return {
        state: 'done',
        detail: `${feed.exports.published} ${feed.exports.published === 1 ? 'feed URL' : 'feed URLs'} live`,
      }
    }
    if (feed.exports.count > 0) return { state: 'active', detail: 'Building' }
    if (run !== null && run.stage !== 'sync') return { state: 'next', detail: PURPOSE.publish }

    return { state: 'soon', detail: PURPOSE.publish }
  })()

  return [
    { key: 'import', name: 'Import', ...importStage },
    { key: 'analysis', name: 'Analysis', ...analysisStage },
    { key: 'mapping', name: 'AI mapping', ...mappingStage },
    { key: 'plan', name: 'Translation plan', ...planStage },
    { key: 'translation', name: 'Translation', ...translationStage },
    { key: 'publish', name: 'Publish', ...publishStage },
  ]
}

const AVAILABLE: ReadonlySet<Stage['key']> = new Set([
  'import',
  'analysis',
  'mapping',
  'plan',
  'translation',
  'publish',
])

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
