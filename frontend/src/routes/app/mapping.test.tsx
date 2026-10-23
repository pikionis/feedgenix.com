import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { beforeEach, expect, it } from 'vitest'
import type { Mapping } from '~/lib/api/types'
import { apiUrl, feed, languagesResponse, mapping, mappingField, sessionUser } from '~/test/fixtures'
import { csrfCookie } from '~/test/handlers'
import { renderRoutes } from '~/test/render'
import { server } from '~/test/server'
import MappingPage from './mapping'

beforeEach(() => {
  server.use(
    csrfCookie(),
    http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ user: sessionUser() })),
    http.get(apiUrl('/v1/feeds/7'), () => HttpResponse.json({ feed: feed({ mapping_status: 'draft' }) })),
    http.get(apiUrl('/languages'), () => HttpResponse.json(languagesResponse)),
  )
})

function serveMapping(...answers: Mapping[]) {
  server.use(
    http.get(apiUrl('/v1/feeds/7/mapping'), () =>
      HttpResponse.json({ mapping: answers.length > 1 ? answers.shift() : answers[0] }),
    ),
  )
}

function renderMapping() {
  return renderRoutes([{ path: '/app/feeds/:feedId/mapping', Component: MappingPage }], '/app/feeds/7/mapping')
}

/** Fields are locked until the session says who is looking: wait for an owner's controls. */
async function asOwner() {
  await screen.findByRole('button', { name: 'Approve the mapping' })
}

it('shows where each field comes from, with its status, reason and examples', async () => {
  serveMapping(mapping())
  renderMapping()

  const title = await screen.findByLabelText('Title (German)')
  expect(title).toHaveValue('Titel')
  expect(screen.getByLabelText('SKU')).toHaveValue('Artikelnummer')
  expect(screen.getByText('e.g. Wanderschuh aus Leder · Regenjacke')).toBeInTheDocument()
  expect(screen.getAllByText('Checked').length).toBeGreaterThan(0)

  const color = screen.getByLabelText('Color (German)')
  expect(color).toHaveValue('')
  expect(screen.getByText('Left empty')).toBeInTheDocument()
  expect(screen.getByText(/look like sizes/)).toBeInTheDocument()
  expect(screen.getByText('Suggested: Grösse')).toBeInTheDocument()
})

it('never offers a price, id or image column to a text field', async () => {
  serveMapping(mapping())
  renderMapping()
  await asOwner()

  const title = screen.getByLabelText('Title (German)')
  expect(within(title).getByRole('option', { name: /Preis CHF/ })).toBeDisabled()
  expect(within(screen.getByLabelText('Price')).getByRole('option', { name: 'Preis CHF' })).toBeEnabled()
})

it('sends a change with the version it was made on, and shows the result', async () => {
  let sent: unknown
  serveMapping(mapping())
  server.use(
    http.put(apiUrl('/v1/feeds/7/mapping/fields'), async ({ request }) => {
      sent = await request.json()

      return HttpResponse.json({
        mapping: mapping({
          version: 5,
          fields: [mappingField({ source_column: 'Beschreibung', origin: 'manual', status: 'needs_input' })],
        }),
      })
    }),
  )
  renderMapping()
  await asOwner()

  await userEvent.selectOptions(screen.getByLabelText('Title (German)'), 'Beschreibung')

  expect(await screen.findByText('Check this')).toBeInTheDocument()
  expect(screen.getByText('Set by you')).toBeInTheDocument()
  expect(sent).toEqual({ version: 4, target: 'title', locale: 'de', column: 'Beschreibung' })
})

it('reloads and says so when someone else changed the mapping', async () => {
  serveMapping(mapping(), mapping({ version: 9 }))
  server.use(
    http.put(apiUrl('/v1/feeds/7/mapping/fields'), () =>
      HttpResponse.json({ error: 'mapping_changed', message: 'Changed.', version: 9 }, { status: 409 }),
    ),
  )
  renderMapping()
  await asOwner()

  await userEvent.selectOptions(screen.getByLabelText('Title (German)'), '')

  expect(await screen.findByRole('alert')).toHaveTextContent('Someone else changed this mapping')
})

it('asks the AI with the corrections, after showing the most it can cost in credits', async () => {
  let sent: unknown
  serveMapping(mapping())
  server.use(
    http.post(apiUrl('/v1/feeds/7/mapping/suggest'), async ({ request }) => {
      sent = await request.json()

      return HttpResponse.json({ mapping: mapping({ ai: { ...mapping().ai, status: 'done' } }) }, { status: 202 })
    }),
  )
  renderMapping()

  expect(await screen.findByText('Up to 1,200 credits; usually far fewer are used.')).toBeInTheDocument()
  await userEvent.type(screen.getByLabelText('Anything the AI should know? (optional)'), 'Grösse is a shoe size.')
  await userEvent.click(screen.getByRole('button', { name: 'Suggest with AI' }))

  await screen.findByRole('button', { name: 'Suggest with AI' })
  expect(sent).toEqual({ instructions: 'Grösse is a shoe size.' })
})

it('explains a shortage of credits with the numbers', async () => {
  serveMapping(mapping())
  server.use(
    http.post(apiUrl('/v1/feeds/7/mapping/suggest'), () =>
      HttpResponse.json(
        { error: 'insufficient_credits', message: 'x', needed_credits: 1200, available_credits: 15 },
        { status: 409 },
      ),
    ),
  )
  renderMapping()

  await userEvent.click(await screen.findByRole('button', { name: 'Suggest with AI' }))

  expect(await screen.findByRole('alert')).toHaveTextContent('needs up to 1,200 credits; your workspace has 15')
})

it('says why AI suggestions are not available', async () => {
  serveMapping(
    mapping({
      ai: {
        status: 'none',
        failure: null,
        available: false,
        unavailable_reason: 'ai_disabled',
        estimate_credits: null,
      },
    }),
  )
  renderMapping()

  expect(await screen.findByText(/AI suggestions are switched off for now/)).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Suggest with AI' })).not.toBeInTheDocument()
})

it('approves the mapping on the version shown', async () => {
  let sent: unknown
  serveMapping(mapping())
  server.use(
    http.post(apiUrl('/v1/feeds/7/mapping/approve'), async ({ request }) => {
      sent = await request.json()

      return HttpResponse.json({ mapping: mapping({ status: 'approved', approved_at: new Date().toISOString() }) })
    }),
  )
  renderMapping()

  await userEvent.click(await screen.findByRole('button', { name: 'Approve the mapping' }))

  expect(await screen.findByText(/Changing a field sends it back to draft/)).toBeInTheDocument()
  expect(sent).toEqual({ version: 4 })
})

it('shows why a mapping cannot be approved yet', async () => {
  serveMapping(mapping())
  server.use(
    http.post(apiUrl('/v1/feeds/7/mapping/approve'), () =>
      HttpResponse.json(
        { error: 'sku_missing', message: 'Choose the column that holds the SKU first.' },
        { status: 422 },
      ),
    ),
  )
  renderMapping()

  await userEvent.click(await screen.findByRole('button', { name: 'Approve the mapping' }))

  expect(await screen.findByRole('alert')).toHaveTextContent('Choose the column that holds the SKU first.')
})

it('lets a member read the mapping without changing it', async () => {
  server.use(
    http.get(apiUrl('/v1/auth/user'), () =>
      HttpResponse.json({ user: sessionUser({ workspace: { ...sessionUser().workspace!, role: 'member' } }) }),
    ),
  )
  serveMapping(mapping())
  renderMapping()

  expect(await screen.findByLabelText('Title (German)')).toBeDisabled()
  expect(screen.queryByRole('button', { name: 'Approve the mapping' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Suggest with AI' })).not.toBeInTheDocument()
})

it('keeps the less common fields folded away until asked for', async () => {
  serveMapping(mapping())
  renderMapping()

  expect(await screen.findByText('1 more field')).toBeInTheDocument()
  expect(screen.getByLabelText('Warranty (German)')).not.toBeVisible()
})

it('says there is nothing to map before the feed has been read', async () => {
  server.use(
    http.get(apiUrl('/v1/feeds/7/mapping'), () =>
      HttpResponse.json({ error: 'no_profile', message: 'x' }, { status: 404 }),
    ),
  )
  renderMapping()

  expect(await screen.findByRole('heading', { name: 'Nothing to map yet' })).toBeInTheDocument()
})

it('asks for the feed language when it could not be told, and files the texts under the choice', async () => {
  let sent: unknown
  serveMapping(mapping({ source_locale: null }))
  server.use(
    http.patch(apiUrl('/v1/feeds/7/mapping'), async ({ request }) => {
      sent = await request.json()

      return HttpResponse.json({ mapping: mapping({ source_locale: 'fr', version: 5 }) })
    }),
  )
  renderMapping()
  await asOwner()

  expect(screen.getByText(/could not tell the feed's language/)).toBeInTheDocument()
  const picker = screen.getByLabelText('The feed is written in')
  await screen.findByRole('option', { name: 'French' })
  await userEvent.selectOptions(picker, 'fr')

  expect(await screen.findByLabelText('The feed is written in')).toHaveValue('fr')
  expect(sent).toEqual({ version: 4, source_locale: 'fr' })
  expect(screen.queryByText(/could not tell the feed's language/)).not.toBeInTheDocument()
})
