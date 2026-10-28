import { describe, expect, it } from 'vitest'
import type { Feed } from '~/lib/api/types'
import { feed, importSummary } from '~/test/fixtures'
import { feedState } from './FeedState'

type Run = NonNullable<Feed['run']>
const run = (overrides: Partial<Run>): Run => ({
  stage: 'plan',
  status: 'awaiting_decision',
  texts_planned: 0,
  texts_done: 0,
  ...overrides,
})
const approved = (overrides: Partial<Feed> = {}) => feed({ mapping_status: 'approved', ...overrides })

describe('feedState', () => {
  it.each([
    ['a file being read', feed({ latest_import: importSummary({ status: 'reading' }) }), 'Reading', 'running'],
    ['no file yet', feed({ latest_import: null, profile_import_id: null }), 'No file yet', 'queued'],
    [
      'a first file that failed',
      feed({ latest_import: importSummary({ status: 'failed' }), profile_import_id: null }),
      'Could not be read',
      'error',
    ],
    ['paused by the plan', approved({ plan_paused: true }), 'Paused by the plan', 'queued'],
    ['a mapping to review', feed({ mapping_status: 'draft' }), 'Review the mapping', 'warn'],
    [
      'products being read',
      approved({ run: run({ stage: 'sync', status: 'running' }) }),
      'Reading products',
      'running',
    ],
    ['a plan to decide', approved({ run: run({}) }), 'Plan the translation', 'warn'],
    ['work that waits', approved({ run: run({ stage: 'translate', status: 'held' }) }), 'Waiting', 'warn'],
    [
      'live feed URLs',
      approved({ run: run({ stage: 'done', status: 'done' }), exports: { count: 1, published: 1 } }),
      'Live',
      'success',
    ],
    [
      'translated, not published',
      approved({ run: run({ stage: 'done', status: 'done' }) }),
      'Ready to publish',
      'success',
    ],
    ['a failed run', approved({ run: run({ stage: 'sync', status: 'failed' }) }), 'Failed', 'error'],
  ])('%s', (_, subject, label, tone) => {
    expect(feedState(subject)).toMatchObject({ label, tone })
  })

  it('says how far a translation is', () => {
    const state = feedState(
      approved({ run: run({ stage: 'translate', status: 'running', texts_planned: 40, texts_done: 10 }) }),
    )

    expect(state).toEqual({ label: 'Translating', tone: 'running', progress: 0.25 })
  })
})
