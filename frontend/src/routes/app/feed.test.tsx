import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { beforeEach, expect, it } from 'vitest'
import type { Feed } from '~/lib/api/types'
import { apiUrl, feed, feedImport, importSummary, sessionUser } from '~/test/fixtures'
import { csrfCookie } from '~/test/handlers'
import { renderRoutes } from '~/test/render'
import { server } from '~/test/server'
import FeedPage from './feed'

const FAILED = importSummary({
  id: 101,
  status: 'failed',
  format: null,
  row_count: null,
  column_count: null,
  failure: {
    reason: 'malformed_xml',
    message: 'The XML is broken at line 3: a tag is not closed.',
    retry_after_seconds: null,
  },
})

beforeEach(() => {
  server.use(
    csrfCookie(),
    http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ user: sessionUser() })),
    http.get(apiUrl('/v1/feeds/7/imports'), () => HttpResponse.json({ data: [importSummary()] })),
    http.get(apiUrl('/v1/imports/100'), () => HttpResponse.json({ import: feedImport() })),
  )
})

/** Answers GET /v1/feeds/7 with each feed in turn, the last one from then on. */
function serveFeed(...answers: Feed[]) {
  server.use(
    http.get(apiUrl('/v1/feeds/7'), () =>
      HttpResponse.json({ feed: answers.length > 1 ? answers.shift() : answers[0] }),
    ),
  )
}

function renderFeed() {
  return renderRoutes(
    [
      { path: '/app/feeds/:feedId', Component: FeedPage },
      { path: '/app/feeds', Component: () => <p>All feeds page</p> },
    ],
    '/app/feeds/7',
  )
}

it('shows how the file was read, what each column holds and sample products', async () => {
  serveFeed(feed())
  renderFeed()

  expect(await screen.findByRole('heading', { name: 'Main shop' })).toBeInTheDocument()
  expect(screen.getByText('Fetched from shop.example')).toBeInTheDocument()
  expect(await screen.findByText('every character is valid UTF-8', { exact: false })).toBeInTheDocument()
  const separator = screen.getAllByText('Separator').find((element) => element.tagName === 'DT')
  expect(separator?.nextElementSibling).toHaveTextContent('Semicolon')
  expect(screen.getByText(/filled and unique in every row/)).toBeInTheDocument()

  const price = within(screen.getByRole('table', { name: 'Columns' })).getByRole('row', { name: /price/ })
  expect(within(price).getByText('Decimal')).toBeInTheDocument()
  expect(within(price).getByText('50%')).toBeInTheDocument()
  expect(
    within(screen.getByRole('table', { name: 'Sample products' })).getByRole('cell', { name: 'Hammer' }),
  ).toBeInTheDocument()
})

it('follows a running import until the file is read', async () => {
  serveFeed(
    feed({ latest_import: importSummary({ status: 'reading', finished_at: null }), profile_import_id: null }),
    feed(),
  )
  renderFeed()

  expect(await screen.findByText('Reading the file…')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Fetch now' })).toBeDisabled()
  expect(await screen.findByRole('heading', { name: 'What we read' }, { timeout: 4000 })).toBeInTheDocument()
  expect(screen.queryByText('Reading the file…')).not.toBeInTheDocument()
})

it('explains a failed import, keeps the last profile, and reads the file again with other options', async () => {
  let reread: unknown = null
  serveFeed(feed({ latest_import: FAILED }))
  server.use(
    http.get(apiUrl('/v1/imports/101'), () =>
      HttpResponse.json({
        import: feedImport({
          ...FAILED,
          can_reread: true,
          encoding: null,
          delimiter: null,
          sku: null,
          columns: [],
          sample_rows: [],
        }),
      }),
    ),
    http.post(apiUrl('/v1/imports/101/reread'), async ({ request }) => {
      reread = await request.json()
      return HttpResponse.json(
        { import: importSummary({ id: 102, origin: 'reread', status: 'queued' }) },
        { status: 202 },
      )
    }),
  )
  renderFeed()

  expect(await screen.findByText('The XML is broken at line 3: a tag is not closed.')).toBeInTheDocument()
  expect(screen.getByText('The profile below is from the last file that was read.')).toBeInTheDocument()
  expect(await screen.findByRole('heading', { name: 'What we read' })).toBeInTheDocument()

  const user = userEvent.setup()
  await user.selectOptions(await screen.findByLabelText('Encoding'), 'Windows-1253')
  await user.type(screen.getByLabelText('Products at (XML)'), 'catalog/products/product')
  await user.click(screen.getByRole('button', { name: 'Read it again' }))

  await expect
    .poll(() => reread)
    .toEqual({ encoding: 'Windows-1253', delimiter: null, xml_item_path: 'catalog/products/product' })
})

it('refuses an item path that is not one, before asking the server', async () => {
  serveFeed(feed({ latest_import: FAILED }))
  server.use(
    http.get(apiUrl('/v1/imports/101'), () =>
      HttpResponse.json({ import: feedImport({ ...FAILED, can_reread: true, format: null }) }),
    ),
  )
  renderFeed()

  const user = userEvent.setup()
  await user.type(await screen.findByLabelText('Products at (XML)'), '/rss//item')
  await user.click(screen.getByRole('button', { name: 'Read it again' }))

  expect(await screen.findByText('Element names separated by /, like rss/channel/item.')).toBeInTheDocument()
})

it('says how long a rate-limited source asked us to wait', async () => {
  serveFeed(
    feed({
      latest_import: importSummary({
        id: 101,
        status: 'failed',
        failure: {
          reason: 'rate_limited',
          message: 'The source is busy and asked us to come back later.',
          retry_after_seconds: 600,
        },
      }),
    }),
  )
  server.use(
    http.get(apiUrl('/v1/imports/101'), () =>
      HttpResponse.json({ import: feedImport({ id: 101, can_reread: false }) }),
    ),
  )
  renderFeed()

  expect(await screen.findByText(/try again in 10 minutes/)).toBeInTheDocument()
})

it('fetches on demand and follows the new import', async () => {
  serveFeed(feed())
  let fetched = 0
  server.use(
    http.post(apiUrl('/v1/feeds/7/fetch'), () => {
      fetched++
      serveFeed(feed({ latest_import: importSummary({ id: 103, status: 'queued', finished_at: null }) }))
      return HttpResponse.json({ import: importSummary({ id: 103, status: 'queued' }) }, { status: 202 })
    }),
  )
  renderFeed()

  await userEvent.setup().click(await screen.findByRole('button', { name: 'Fetch now' }))

  expect(await screen.findByText('Waiting for its turn…')).toBeInTheDocument()
  expect(fetched).toBe(1)
})

it('says so when the feed is already being read', async () => {
  serveFeed(feed())
  server.use(
    http.post(apiUrl('/v1/feeds/7/fetch'), () =>
      HttpResponse.json(
        { error: 'feed_busy', message: 'This feed is being read right now. Try again when it finishes.' },
        { status: 409 },
      ),
    ),
  )
  renderFeed()

  await userEvent.setup().click(await screen.findByRole('button', { name: 'Fetch now' }))

  expect(await screen.findByText('This feed is being read right now. Try again when it finishes.')).toBeInTheDocument()
})

it('offers the first upload to a feed without a file', async () => {
  serveFeed(feed({ source: 'upload', host: null, latest_import: null, profile_import_id: null }))
  renderFeed()

  expect(await screen.findByText('No file yet. Upload one and we read it straight away.')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Upload a new file' })).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Fetch now' })).not.toBeInTheDocument()
})

it('lets owners delete a feed, after asking', async () => {
  serveFeed(feed())
  let deleted = false
  server.use(
    http.delete(apiUrl('/v1/feeds/7'), () => {
      deleted = true
      return new HttpResponse(null, { status: 204 })
    }),
    http.get(apiUrl('/v1/feeds'), () => HttpResponse.json({ data: [] })),
    http.get(apiUrl('/v1/workspace'), () => HttpResponse.json({})),
  )
  renderFeed()

  const user = userEvent.setup()
  await user.click(await screen.findByRole('button', { name: 'Delete' }))
  expect(screen.getByRole('dialog')).toHaveTextContent('Its imports and stored files are deleted.')
  await user.click(screen.getByRole('button', { name: 'Delete feed' }))

  expect(await screen.findByText('All feeds page')).toBeInTheDocument()
  expect(deleted).toBe(true)
})

it('does not offer members to delete', async () => {
  server.use(
    http.get(apiUrl('/v1/auth/user'), () =>
      HttpResponse.json({
        user: sessionUser({ workspace: { id: 10, name: 'Shop', slug: 'shop', role: 'member', plan: 'free' } }),
      }),
    ),
  )
  serveFeed(feed())
  renderFeed()

  expect(await screen.findByRole('button', { name: 'Fetch now' })).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Delete' })).not.toBeInTheDocument()
})

it('says a feed of another workspace does not exist', async () => {
  server.use(http.get(apiUrl('/v1/feeds/7'), () => HttpResponse.json({ message: 'Not found.' }, { status: 404 })))
  renderFeed()

  expect(await screen.findByText('This feed does not exist')).toBeInTheDocument()
})

it('follows the translation from the feed page, and leads to it', async () => {
  const translating = { stage: 'translate', status: 'running', texts_planned: 40, texts_done: 10 } as const
  serveFeed(
    feed({ mapping_status: 'approved', run: translating }),
    feed({ mapping_status: 'approved', run: { ...translating, stage: 'done', status: 'done', texts_done: 40 } }),
  )
  renderFeed()

  expect(await screen.findByText('Translating: 10 of 40 texts.')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'See the progress' })).toHaveAttribute('href', '/app/feeds/7/translation')
  // Polled while it works, until it is done.
  expect(await screen.findByText(/^Translated\./, {}, { timeout: 4000 })).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Open the translation' })).toBeInTheDocument()
})

it('offers no translation before the mapping is approved', async () => {
  serveFeed(feed({ mapping_status: 'draft' }))
  renderFeed()

  expect(await screen.findByRole('link', { name: 'Review the mapping' })).toBeInTheDocument()
  expect(screen.queryByRole('heading', { name: 'Translation' })).not.toBeInTheDocument()
})
