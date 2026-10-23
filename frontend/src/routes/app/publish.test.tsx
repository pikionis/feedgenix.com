import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { beforeEach, expect, it } from 'vitest'
import type { ExportsResponse } from '~/lib/api/types'
import { apiUrl, exportBuild, exportProfile, exportsResponse, feed, sessionUser } from '~/test/fixtures'
import { csrfCookie } from '~/test/handlers'
import { renderRoutes } from '~/test/render'
import { server } from '~/test/server'
import PublishPage from './publish'

beforeEach(() => {
  server.use(
    csrfCookie(),
    http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ user: sessionUser() })),
    http.get(apiUrl('/v1/feeds/7'), () =>
      HttpResponse.json({
        feed: feed({
          mapping_status: 'approved',
          run: { stage: 'done', status: 'done', texts_planned: 5, texts_done: 5 },
        }),
      }),
    ),
  )
})

/** Answers the exports with each answer in turn, the last one from then on. */
function serveExports(...answers: ExportsResponse[]) {
  server.use(
    http.get(apiUrl('/v1/feeds/7/exports'), () => HttpResponse.json(answers.length > 1 ? answers.shift() : answers[0])),
  )
}

function renderPublish() {
  return renderRoutes([{ path: '/app/feeds/:feedId/publish', Component: PublishPage }], '/app/feeds/7/publish')
}

it('shows each feed URL to copy, the build it serves and what the channel is missing', async () => {
  const user = userEvent.setup()
  const published = exportBuild({ report: { left_out: 0, missing: { 'products-en.tsv': { link: 5 } }, examples: [] } })
  serveExports(exportsResponse([exportProfile({ published, latest: published })]))
  renderPublish()

  const urls = await screen.findByRole('list', { name: 'Google: feed URLs' })
  const english = within(urls).getByLabelText('English: feed URL')
  expect(english).toHaveValue(`${window.location.origin}/f/${'T'.repeat(40)}/products-en.tsv`)
  expect(within(urls).getAllByRole('link', { name: 'The same, compressed (.gz)' })[1]).toHaveAttribute(
    'href',
    `${window.location.origin}/f/${'T'.repeat(40)}/products-en.tsv.gz`,
  )
  expect(screen.getByText('Live')).toBeInTheDocument()
  expect(screen.getByText(/5 products · 4 KB/)).toBeInTheDocument()
  expect(screen.getByText(/requires/)).toHaveTextContent(
    'Google Merchant Center requires link: 5 products have none in products-en.tsv.',
  )

  await user.click(within(urls).getAllByRole('button', { name: 'Copy' })[1]!)
  expect(await navigator.clipboard.readText()).toBe(`${window.location.origin}/f/${'T'.repeat(40)}/products-en.tsv`)
  expect(within(urls).getByRole('button', { name: 'Copied' })).toBeInTheDocument()
})

it('creates an export for a channel, offering only the formats and files it takes', async () => {
  let sent: unknown
  const created = exportProfile({ published: null, latest: null })
  serveExports(exportsResponse([]))
  server.use(
    http.post(apiUrl('/v1/feeds/7/exports'), async ({ request }) => {
      sent = await request.json()
      serveExports(exportsResponse([exportProfile()]))

      return HttpResponse.json({ export: created }, { status: 201 })
    }),
  )
  renderPublish()

  const form = await screen.findByRole('heading', { name: 'New export' })
  expect(form).toBeInTheDocument()
  await userEvent.selectOptions(screen.getByLabelText('For'), 'google_merchant')
  expect(screen.getByLabelText('Name')).toHaveValue('Google Merchant Center')
  expect(screen.getByLabelText('Format')).toBeDisabled()
  expect(screen.getByLabelText('Format')).toHaveValue('tsv')
  expect(
    within(screen.getByLabelText('Format'))
      .getAllByRole('option')
      .map((option) => option.textContent),
  ).toEqual(['TSV (tab-separated)'])
  expect(screen.getByLabelText('Files')).toHaveValue('per_language')
  await userEvent.click(screen.getByRole('checkbox', { name: 'German' }))
  await userEvent.click(screen.getByRole('button', { name: 'Create the export' }))

  expect(sent).toEqual({
    name: 'Google Merchant Center',
    preset: 'google_merchant',
    dialect: 'tsv',
    layout: 'per_language',
    locales: ['en'],
  })
  expect(await screen.findByRole('list', { name: 'Google: feed URLs' })).toBeInTheDocument()
  expect(screen.queryByRole('heading', { name: 'New export' })).not.toBeInTheDocument()
})

it('follows a build until it is live', async () => {
  const building = exportProfile({ published: null, latest: exportBuild({ status: 'building', published_at: null }) })
  serveExports(exportsResponse([building]), exportsResponse([exportProfile()]))
  renderPublish()

  expect(await screen.findByText('Building…')).toBeInTheDocument()
  expect(screen.getByText(/Nothing is published yet/)).toBeInTheDocument()
  expect(await screen.findByText('Live', {}, { timeout: 4000 })).toBeInTheDocument()
})

it('says why a build was held back, and publishes it anyway when told to', async () => {
  let sent: unknown
  const held = exportBuild({
    id: 91,
    status: 'rejected',
    rows: 20,
    failure_reason: 'shrink',
    failure_message: 'The new build has 20 rows, the published one 60: more than 30% fewer.',
    published_at: null,
  })
  serveExports(exportsResponse([exportProfile({ published: exportBuild({ rows: 60 }), latest: held })]))
  server.use(
    http.post(apiUrl('/v1/exports/30/build'), async ({ request }) => {
      sent = await request.json()

      return HttpResponse.json({ export: exportProfile() }, { status: 202 })
    }),
  )
  renderPublish()

  expect(await screen.findByText(/20 rows, the published one 60/)).toBeInTheDocument()
  await userEvent.click(screen.getByRole('button', { name: 'Publish it anyway' }))
  expect(sent).toEqual({ allow_shrink: true })
})

it('makes new URLs only after asking, and says until when the old ones work', async () => {
  let rotated = false
  serveExports(exportsResponse())
  server.use(
    http.post(apiUrl('/v1/exports/30/token'), () => {
      rotated = true
      serveExports(exportsResponse([exportProfile({ previous_urls_until: '2027-01-13T10:00:00+00:00' })]))

      return HttpResponse.json({ export: exportProfile() })
    }),
  )
  renderPublish()

  await userEvent.click(await screen.findByRole('button', { name: 'New URLs' }))
  const dialog = screen.getByRole('dialog', { name: 'New URLs?' })
  expect(dialog).toHaveTextContent('keep working for 72 hours')
  expect(rotated).toBe(false)
  await userEvent.click(within(dialog).getByRole('button', { name: 'Make new URLs' }))

  expect(await screen.findByText(/The previous URLs keep working until/)).toBeInTheDocument()
  expect(rotated).toBe(true)
})

it('pauses an export with the version it was shown with, and serves an earlier build again', async () => {
  const sent: unknown[] = []
  serveExports(
    exportsResponse([
      exportProfile({ version: 4, earlier: [{ id: 80, rows: 5, published_at: '2027-01-09T10:00:00+00:00' }] }),
    ]),
  )
  server.use(
    http.patch(apiUrl('/v1/exports/30'), async ({ request }) => {
      sent.push(await request.json())

      return HttpResponse.json({ export: exportProfile({ status: 'paused' }) })
    }),
    http.post(apiUrl('/v1/exports/30/builds/80/publish'), () => {
      sent.push('earlier 80')

      return HttpResponse.json({ export: exportProfile() })
    }),
  )
  renderPublish()

  await userEvent.click(await screen.findByRole('button', { name: 'Pause' }))
  await userEvent.click(screen.getByText('Earlier builds'))
  await userEvent.click(screen.getByRole('button', { name: 'Serve this one again' }))

  await expect.poll(() => sent).toEqual([{ version: 4, status: 'paused' }, 'earlier 80'])
})

it('lets members copy the URLs, never change them', async () => {
  const user = sessionUser()
  server.use(
    http.get(apiUrl('/v1/auth/user'), () =>
      HttpResponse.json({ user: { ...user, workspace: user.workspace && { ...user.workspace, role: 'member' } } }),
    ),
  )
  serveExports(exportsResponse())
  renderPublish()

  expect(await screen.findByRole('list', { name: 'Google: feed URLs' })).toBeInTheDocument()
  expect(screen.getAllByRole('button', { name: 'Copy' })).toHaveLength(2)
  expect(screen.queryByRole('button', { name: 'Build now' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'New export' })).not.toBeInTheDocument()
})

it('asks to approve the mapping first when there is no catalogue yet', async () => {
  server.use(
    http.get(apiUrl('/v1/feeds/7'), () => HttpResponse.json({ feed: feed({ mapping_status: 'draft', run: null }) })),
  )
  serveExports(exportsResponse([]))
  renderPublish()

  expect(await screen.findByText(/approve it first/)).toBeInTheDocument()
})
