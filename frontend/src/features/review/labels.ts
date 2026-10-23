import type { QualityFailure, ReviewFilter, ReviewText } from '~/lib/api/types'

/** What each check found, in the reviewer's words. */
export const FAILURES: Record<QualityFailure, string> = {
  empty: 'The answer was empty',
  numbers: 'Numbers or codes differ from the source',
  html: 'The HTML differs from the source',
  script: "Not written in the language's own script",
  levels: 'The category has other levels than the source',
  length: 'Much shorter or longer than the source',
  echo: 'Left untranslated',
  brands: 'Brand names went missing',
}

export const FILTERS: { key: ReviewFilter; label: string }[] = [
  { key: 'all', label: 'All' },
  { key: 'review', label: 'To review' },
  { key: 'missing', label: 'Missing' },
  { key: 'manual', label: 'Yours' },
]

export type TextTone = 'success' | 'neutral' | 'warn' | 'error'

/** Where a translation stands, in a word or two, with how much attention it needs. */
export function textState(text: ReviewText): { label: string; tone: TextTone } {
  const target = text.target
  if (text.provided) return { label: 'From the feed', tone: 'neutral' }
  if (target === null) return { label: 'Not translated yet', tone: 'neutral' }
  if (target.stale) return { label: 'The source changed', tone: 'warn' }
  if (target.hard) return { label: 'Not published: a check failed', tone: 'error' }
  if (target.status !== 'ready') return { label: 'Waiting', tone: 'neutral' }
  if (target.origin === 'manual') return { label: 'Yours', tone: 'success' }
  if (target.origin === 'approved') return { label: 'Approved by you', tone: 'success' }
  if (target.failures.length > 0) return { label: 'Check this', tone: 'warn' }
  if (target.origin === 'source') return { label: 'Kept as is', tone: 'neutral' }
  if (target.origin === 'tm') return { label: 'From the memory', tone: 'success' }

  return { label: 'Translated', tone: 'success' }
}

/** The AI's translation was flagged and can be published as it is. */
export function canApprove(text: ReviewText): boolean {
  const target = text.target

  return target !== null && target.origin === 'ai' && !target.stale && (target.hard || target.failures.length > 0)
}
