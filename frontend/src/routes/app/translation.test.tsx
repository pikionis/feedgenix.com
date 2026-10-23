import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { beforeEach, expect, it } from 'vitest'
import type { TranslationPlan } from '~/lib/api/types'
import { apiUrl, feed, pipelineRun, sessionUser, translationPlan } from '~/test/fixtures'
import { csrfCookie } from '~/test/handlers'
import { renderRoutes } from '~/test/render'
import { server } from '~/test/server'
import TranslationPage from './translation'

beforeEach(() => {
  server.use(
    csrfCookie(),
    http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ user: sessionUser() })),
    http.get(apiUrl('/v1/feeds/7'), () => HttpResponse.json({ feed: feed({ mapping_status: 'approved' }) })),
  )
})

/** Answers GET the plan with each plan in turn, the last one from then on. */
function servePlan(...answers: TranslationPlan[]) {
  server.use(
    http.get(apiUrl('/v1/feeds/7/translation-plan'), () =>
      HttpResponse.json({ translation_plan: answers.length > 1 ? answers.shift() : answers[0] }),
    ),
  )
}

function renderPlan() {
  return renderRoutes(
    [{ path: '/app/feeds/:feedId/translation', Component: TranslationPage }],
    '/app/feeds/7/translation',
  )
}

/** Controls are locked until the session says who is looking: wait for an owner's. */
async function asOwner() {
  await screen.findByRole('button', { name: 'Start translating' })
}

it('shows the texts, the languages, the work with the memory, and the estimate in credits', async () => {
  servePlan(translationPlan())
  renderPlan()
  await asOwner()

  expect(screen.getByRole('checkbox', { name: 'English' })).toBeChecked()
  expect(screen.getByRole('checkbox', { name: 'French' })).not.toBeChecked()
  expect(screen.getByLabelText('Title')).toHaveValue('translate')
  expect(screen.getByLabelText('Size')).toHaveValue('keep')
  expect(screen.getAllByText('In the feed: German (5)')).toHaveLength(2)

  const table = screen.getByRole('table')
  const title = within(table).getByRole('row', { name: /Title/ })
  expect(
    within(title)
      .getAllByRole('cell')
      .map((cell) => cell.textContent),
  ).toEqual(['Title', 'English', '5', '2', '3'])
  expect(within(within(table).getByRole('row', { name: /Size/ })).getByText('kept as is')).toBeInTheDocument()
  expect(screen.getByText(/3 texts with AI: about/)).toHaveTextContent('about 1,200 credits, at most 4,800.')
  expect(screen.getByRole('link', { name: 'Add one in the settings' })).toHaveAttribute('href', '/app/settings')
})

it('saves a change with the version it was made on, keeping the other choices', async () => {
  let sent: unknown
  servePlan(translationPlan())
  server.use(
    http.put(apiUrl('/v1/feeds/7/translation-plan'), async ({ request }) => {
      sent = await request.json()

      return HttpResponse.json({ translation_plan: translationPlan({ version: 3, target_locales: ['en', 'fr'] }) })
    }),
  )
  renderPlan()
  await asOwner()

  await userEvent.click(screen.getByRole('checkbox', { name: 'French' }))

  expect(await screen.findByRole('checkbox', { name: 'French', checked: true })).toBeInTheDocument()
  expect(sent).toEqual({ version: 2, fields: { title: 'translate', size: 'keep' }, target_locales: ['en', 'fr'] })
})

it('turns on translating every new file by itself, keeping the plan as it is', async () => {
  let sent: unknown
  servePlan(translationPlan())
  server.use(
    http.put(apiUrl('/v1/feeds/7/translation-plan'), async ({ request }) => {
      sent = await request.json()

      return HttpResponse.json({ translation_plan: translationPlan({ version: 3, auto_translate: true }) })
    }),
  )
  renderPlan()
  await asOwner()
  const auto = screen.getByRole('checkbox', { name: /Translate every new file by itself/ })
  expect(auto).not.toBeChecked()

  await userEvent.click(auto)

  expect(
    await screen.findByRole('checkbox', { name: /Translate every new file by itself/, checked: true }),
  ).toBeInTheDocument()
  expect(sent).toEqual({
    version: 2,
    fields: { title: 'translate', size: 'keep' },
    target_locales: ['en'],
    auto_translate: true,
  })
})

it('sends no switch with other changes, so a save never turns it off', async () => {
  let sent: Record<string, unknown> = {}
  servePlan(translationPlan({ auto_translate: true }))
  server.use(
    http.put(apiUrl('/v1/feeds/7/translation-plan'), async ({ request }) => {
      sent = (await request.json()) as Record<string, unknown>

      return HttpResponse.json({ translation_plan: translationPlan({ version: 3, auto_translate: true }) })
    }),
  )
  renderPlan()
  await asOwner()

  await userEvent.selectOptions(screen.getByLabelText('Title'), 'skip')

  await expect.poll(() => Object.keys(sent)).toEqual(['version', 'fields', 'target_locales'])
})

it('reloads the plan when someone else changed it meanwhile', async () => {
  servePlan(translationPlan(), translationPlan({ version: 9, target_locales: ['fr'] }))
  server.use(
    http.put(apiUrl('/v1/feeds/7/translation-plan'), () =>
      HttpResponse.json({ error: 'plan_changed', message: 'Changed.', version: 9 }, { status: 409 }),
    ),
  )
  renderPlan()
  await asOwner()

  await userEvent.selectOptions(screen.getByLabelText('Title'), 'skip')

  expect(await screen.findByRole('alert')).toHaveTextContent('Someone else changed this plan meanwhile')
  expect(await screen.findByRole('checkbox', { name: 'French', checked: true })).toBeInTheDocument()
  expect(screen.getByLabelText('Title')).toHaveValue('translate')
})

it('starts the plan as shown, then follows the translation until it is done', async () => {
  let sent: unknown
  servePlan(translationPlan())
  server.use(
    http.post(apiUrl('/v1/feeds/7/translation-plan/start'), async ({ request }) => {
      sent = await request.json()
      const counts = { planned: 3, kept: 5, memory: 2 }
      servePlan(
        translationPlan({ run: pipelineRun({ stage: 'done', status: 'done', counts: { ...counts, texts_done: 3 } }) }),
      )
      const running = pipelineRun({ stage: 'translate', status: 'running', counts: { ...counts, texts_done: 1 } })

      return HttpResponse.json({ translation_plan: translationPlan({ run: running }) }, { status: 202 })
    }),
  )
  renderPlan()
  await asOwner()

  await userEvent.click(screen.getByRole('button', { name: 'Start translating' }))

  expect(sent).toEqual({ version: 2 })
  expect(await screen.findByRole('heading', { name: 'Translating…' })).toBeInTheDocument()
  expect(screen.getByText('1 / 3')).toBeInTheDocument()
  expect(screen.getByText(/5 texts kept as they are · 2 texts from the translation memory/)).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Start translating' })).toBeDisabled()
  // Polled while it works, until it is done.
  expect(await screen.findByRole('heading', { name: 'Translated' }, { timeout: 4000 })).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Translate what is new' })).toBeEnabled()
})

it('says why the work waits, and which texts failed', async () => {
  const warning = {
    code: 'held_credits',
    message: 'Some texts wait for credits: the workspace does not hold enough for them.',
    count: 1,
  }
  servePlan(
    translationPlan({
      run: pipelineRun({
        stage: 'translate',
        status: 'held',
        counts: { planned: 10, texts_done: 6, texts_failed: 1, texts_held: 3 },
        warnings: [warning],
      }),
    }),
  )
  renderPlan()

  expect(await screen.findByRole('heading', { name: 'Waiting' })).toBeInTheDocument()
  expect(screen.getByText(/Some texts wait for credits/)).toBeInTheDocument()
  expect(screen.getByText(/1 text could not be translated/)).toBeInTheDocument()
  expect(screen.getByText('6 / 10')).toBeInTheDocument()
})

it('has no estimate while AI is off, and says the work would wait', async () => {
  servePlan(
    translationPlan({
      estimate: { available: false, unavailable_reason: 'ai_disabled', about_credits: null, at_most_credits: null },
    }),
  )
  renderPlan()

  expect(await screen.findByText(/AI is not switched on yet/)).toBeInTheDocument()
  expect(screen.queryByText(/with AI: about/)).not.toBeInTheDocument()
})

it('cannot start without a language', async () => {
  servePlan(translationPlan({ target_locales: [], work: [] }))
  renderPlan()
  await asOwner()

  expect(screen.getByText('Choose a language to translate into.')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Start translating' })).toBeDisabled()
})

it('cannot start while the products are read', async () => {
  servePlan(translationPlan({ run: pipelineRun({ stage: 'sync', status: 'running' }) }))
  renderPlan()
  await asOwner()

  expect(screen.getByText('Reading the products into the catalogue…')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Start translating' })).toBeDisabled()
})

it('reads the products again after a failure', async () => {
  let read = false
  const failure = 'The feed could not be read into the catalogue.'
  servePlan(translationPlan({ run: pipelineRun({ stage: 'sync', status: 'failed', failure_message: failure }) }))
  server.use(
    http.post(apiUrl('/v1/feeds/7/catalog/sync'), () => {
      read = true
      const queued = translationPlan({ run: pipelineRun({ stage: 'sync', status: 'queued' }) })

      return HttpResponse.json({ translation_plan: queued }, { status: 202 })
    }),
  )
  renderPlan()

  expect(await screen.findByRole('alert')).toHaveTextContent(failure)
  await userEvent.click(await screen.findByRole('button', { name: 'Read the products again' }))

  expect(read).toBe(true)
  expect(await screen.findByText('Reading the products into the catalogue…')).toBeInTheDocument()
})

it('lets members look, never change', async () => {
  const user = sessionUser()
  server.use(
    http.get(apiUrl('/v1/auth/user'), () =>
      HttpResponse.json({ user: { ...user, workspace: user.workspace && { ...user.workspace, role: 'member' } } }),
    ),
  )
  servePlan(translationPlan())
  renderPlan()

  expect(await screen.findByRole('checkbox', { name: 'English' })).toBeDisabled()
  expect(screen.getByLabelText('Title')).toBeDisabled()
  expect(screen.queryByRole('button', { name: 'Start translating' })).not.toBeInTheDocument()
})
