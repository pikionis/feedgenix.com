import type { Feed } from '~/lib/api/types'
import { cn } from '~/lib/utils'
import { STATUS } from './labels'

export type FeedTone = 'queued' | 'running' | 'success' | 'warn' | 'error'

export interface FeedState {
  label: string
  tone: FeedTone
  /** 0–1 while texts are being translated. */
  progress?: number
}

/**
 * Where a feed stands, in a word or two (the app kit's status column): what runs now, what waits
 * for a person, or that its feed URLs are live.
 */
export function feedState(feed: Feed): FeedState {
  const status = feed.latest_import?.status
  if (status === 'queued' || status === 'fetching' || status === 'reading') {
    return { label: STATUS[status].label, tone: 'running' }
  }
  if (feed.plan_paused) return { label: 'Paused by the plan', tone: 'queued' }
  if (feed.profile_import_id === null) {
    return status === 'failed'
      ? { label: 'Could not be read', tone: 'error' }
      : { label: 'No file yet', tone: 'queued' }
  }
  if (feed.mapping_status !== 'approved') return { label: 'Review the mapping', tone: 'warn' }

  const run = feed.run
  if (run?.status === 'failed') return { label: 'Failed', tone: 'error' }
  if (run?.stage === 'sync') return { label: 'Reading products', tone: 'running' }
  if (run?.stage === 'translate' && run.status === 'held') return { label: 'Waiting', tone: 'warn' }
  if (run?.stage === 'translate') {
    return {
      label: 'Translating',
      tone: 'running',
      progress: run.texts_planned > 0 ? run.texts_done / run.texts_planned : 0,
    }
  }
  if (feed.exports.published > 0) return { label: 'Live', tone: 'success' }
  if (run?.stage === 'plan') return { label: 'Plan the translation', tone: 'warn' }

  return { label: 'Ready to publish', tone: 'success' }
}

// The dot carries the colour; the words stay in the text colour (the app kit's badges).
const TONES: Record<FeedTone, { badge: string; dot: string }> = {
  queued: { badge: 'bg-queued-bg', dot: 'bg-queued' },
  running: { badge: 'bg-primary/10', dot: 'bg-primary animate-pulse' },
  success: { badge: 'bg-success-bg', dot: 'bg-success' },
  warn: { badge: 'bg-warn-bg', dot: 'bg-warn' },
  error: { badge: 'bg-error-bg', dot: 'bg-error' },
}

export function FeedStateBadge({ state, className }: { state: FeedState; className?: string }) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium whitespace-nowrap text-foreground',
        TONES[state.tone].badge,
        className,
      )}
    >
      <span className={cn('size-1.5 rounded-full', TONES[state.tone].dot)} aria-hidden="true" />
      {state.label}
      {state.progress !== undefined && (
        <span className="h-1 w-12 overflow-hidden rounded-full bg-primary/15" aria-hidden="true">
          <span className="block h-full bg-primary" style={{ width: `${Math.round(state.progress * 100)}%` }} />
        </span>
      )}
    </span>
  )
}
