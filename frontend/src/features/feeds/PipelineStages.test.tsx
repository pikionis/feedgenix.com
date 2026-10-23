import { render, screen, within } from '@testing-library/react'
import { describe, expect, it } from 'vitest'
import { feed, importSummary } from '~/test/fixtures'
import { PipelineStages, pipelineStages, type StageState } from './PipelineStages'

const states = (f: Parameters<typeof pipelineStages>[0]): StageState[] => pipelineStages(f).map((stage) => stage.state)

describe('pipelineStages', () => {
  it('a read feed: import and analysis done, mapping next, the rest coming', () => {
    const stages = pipelineStages(feed({ latest_import: importSummary({ row_count: 4832, column_count: 23 }) }))

    expect(stages.map((s) => s.state)).toEqual(['done', 'done', 'next', 'soon', 'soon', 'soon'])
    expect(stages[0]?.detail).toBe('Downloaded from shop.example')
    expect(stages[1]?.detail).toBe('4,832 rows · 23 columns')
  })

  it('an uploaded file names the file', () => {
    const stages = pipelineStages(
      feed({ source: 'upload', latest_import: importSummary({ origin: 'upload', original_name: 'alpine.csv' }) }),
    )

    expect(stages[0]?.detail).toBe('alpine.csv')
  })

  it('a new feed waits for its first file; nothing later is next', () => {
    expect(states(feed({ latest_import: null, profile_import_id: null }))).toEqual([
      'next',
      'soon',
      'soon',
      'soon',
      'soon',
      'soon',
    ])
  })

  it.each([
    ['queued', ['active', 'soon']],
    ['fetching', ['active', 'soon']],
  ] as const)('%s: the import is in progress', (status, expected) => {
    const f = feed({
      latest_import: importSummary({ status, row_count: null, column_count: null }),
      profile_import_id: null,
    })

    expect(states(f).slice(0, 2)).toEqual(expected)
  })

  it('reading: the import is done and the analysis in progress', () => {
    const f = feed({
      latest_import: importSummary({ status: 'reading', row_count: null, column_count: null }),
      profile_import_id: null,
    })

    expect(states(f).slice(0, 3)).toEqual(['done', 'active', 'soon'])
  })

  it('a failed download fails the import, never the analysis', () => {
    const f = feed({
      latest_import: importSummary({ origin: 'fetch', status: 'failed', row_count: null, column_count: null }),
      profile_import_id: null,
    })

    expect(states(f).slice(0, 3)).toEqual(['failed', 'soon', 'soon'])
  })

  it('an unreadable upload fails the analysis', () => {
    const f = feed({
      source: 'upload',
      latest_import: importSummary({ origin: 'upload', status: 'failed', row_count: null, column_count: null }),
      profile_import_id: null,
    })

    expect(states(f).slice(0, 3)).toEqual(['done', 'failed', 'soon'])
  })

  it('a failed import over an earlier profile keeps the earlier result', () => {
    const f = feed({
      latest_import: importSummary({ id: 101, status: 'failed', row_count: null, column_count: null }),
      profile_import_id: 100,
    })

    const stages = pipelineStages(f)
    expect(stages.map((s) => s.state).slice(0, 3)).toEqual(['done', 'done', 'next'])
    expect(stages[1]?.detail).toBe('Read from an earlier file')
  })

  it('an unchanged file still shows what was read', () => {
    expect(pipelineStages(feed({ latest_import: importSummary({ status: 'unchanged' }) }))[1]?.state).toBe('done')
  })

  it('never shows a stage that does not exist yet as running or done', () => {
    for (const status of ['queued', 'fetching', 'reading', 'profiled', 'unchanged', 'failed'] as const) {
      const later = pipelineStages(feed({ latest_import: importSummary({ status }) })).slice(2)
      expect(later.every((stage) => stage.state === 'next' || stage.state === 'soon')).toBe(true)
    }
  })
})

it('lists the six stages in order and says which are coming', () => {
  render(<PipelineStages feed={feed()} />)

  const steps = within(screen.getByRole('list', { name: 'Pipeline' })).getAllByRole('listitem')
  expect(steps.map((step) => step.querySelector('p')?.textContent)).toEqual([
    'Import',
    'Analysis',
    'AI mapping',
    'Translation plan',
    'Translation',
    'Publish',
  ])
  expect(steps[2]).toHaveTextContent('Next · coming soon')
  expect(steps[5]).toHaveTextContent('Coming soon')
  expect(steps[0]).not.toHaveTextContent('Coming soon')
  expect(steps[1]).toHaveTextContent('Done')
})
