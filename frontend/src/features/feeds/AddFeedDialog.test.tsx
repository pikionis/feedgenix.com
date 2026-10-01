import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { useParams } from 'react-router'
import { beforeEach, expect, it } from 'vitest'
import { UpgradeDialog } from '~/features/billing/UpgradeDialog'
import { apiUrl, feed, feedLimit, importSummary, plansResponse, workspace } from '~/test/fixtures'
import { csrfCookie } from '~/test/handlers'
import { renderRoutes } from '~/test/render'
import { server } from '~/test/server'
import { AddFeedDialog } from './AddFeedDialog'

const UPLOAD_ID = '0190c5a4-0000-7000-8000-000000000001'

let created: unknown[]

beforeEach(() => {
  created = []
  server.use(
    csrfCookie(),
    http.get(apiUrl('/v1/feeds'), () => HttpResponse.json({ data: [] })),
    http.get(apiUrl('/v1/workspace'), () => HttpResponse.json(workspace())),
    http.get(apiUrl('/plans'), () => HttpResponse.json(plansResponse)),
  )
})

function FeedPage() {
  return <p>Feed page {useParams().feedId}</p>
}

function renderDialog() {
  let closed = false
  renderRoutes(
    [
      {
        path: '/app/feeds',
        Component: () => (
          <>
            <AddFeedDialog onClose={() => (closed = true)} />
            <UpgradeDialog />
          </>
        ),
      },
      { path: '/app/feeds/:feedId', Component: FeedPage },
    ],
    '/app/feeds',
  )

  return { closed: () => closed }
}

function acceptFeeds(answer: () => Response = () => HttpResponse.json({ feed: feed() }, { status: 201 })) {
  server.use(
    http.post(apiUrl('/v1/feeds'), async ({ request }) => {
      created.push(await request.json())
      return answer()
    }),
  )
}

function acceptUploads(chunk: (attempt: number) => Response = () => new HttpResponse(null, { status: 204 })) {
  let attempts = 0
  server.use(
    http.post(apiUrl('/v1/feeds/7/uploads'), () =>
      HttpResponse.json(
        {
          upload: {
            id: UPLOAD_ID,
            filename: 'catalogue.csv',
            size: 8,
            chunk_size: 5_242_880,
            total_chunks: 1,
            expires_at: '2027-01-11T10:00:00+00:00',
          },
        },
        { status: 201 },
      ),
    ),
    http.put(apiUrl(`/v1/feeds/7/uploads/${UPLOAD_ID}/chunks/0`), () => chunk(++attempts)),
    http.post(apiUrl(`/v1/feeds/7/uploads/${UPLOAD_ID}/complete`), () =>
      HttpResponse.json({ import: importSummary({ origin: 'upload', status: 'queued' }) }, { status: 202 }),
    ),
  )
}

async function fill(name: string, url?: string) {
  const user = userEvent.setup()
  await user.type(await screen.findByLabelText('Name'), name)
  if (url !== undefined) await user.type(screen.getByLabelText('Feed URL'), url)

  return user
}

it('adds a URL feed and opens its page', async () => {
  acceptFeeds()
  renderDialog()

  const user = await fill('Main shop', 'https://shop.example/feed.xml')
  await user.click(screen.getByRole('button', { name: 'Add feed' }))

  expect(await screen.findByText('Feed page 7')).toBeInTheDocument()
  expect(created).toEqual([{ name: 'Main shop', url: 'https://shop.example/feed.xml' }])
})

it('checks the address before sending anything', async () => {
  acceptFeeds()
  renderDialog()

  const user = await fill('Main shop', 'shop.example/feed.xml')
  await user.click(screen.getByRole('button', { name: 'Add feed' }))

  expect(await screen.findByText('Enter the full address, starting with https://.')).toBeInTheDocument()
  expect(created).toEqual([])
})

it('shows why the server refused the address, on the field', async () => {
  const reason = 'This address leads to a private network, which feeds cannot be fetched from.'
  acceptFeeds(() => HttpResponse.json({ message: reason, errors: { url: [reason] } }, { status: 422 }))
  renderDialog()

  const user = await fill('Main shop', 'https://intranet.example/feed')
  await user.click(screen.getByRole('button', { name: 'Add feed' }))

  expect(await screen.findByText(reason)).toBeInTheDocument()
  expect(screen.getByLabelText('Feed URL')).toHaveAttribute('aria-invalid', 'true')
})

it('gives way to the upgrade dialog over the plan’s feed limit', async () => {
  acceptFeeds(() => HttpResponse.json(feedLimit, { status: 402 }))
  const dialog = renderDialog()

  const user = await fill('Second shop', 'https://shop.example/two.xml')
  await user.click(screen.getByRole('button', { name: 'Add feed' }))

  expect(await screen.findByText(feedLimit.message)).toBeInTheDocument()
  expect(dialog.closed()).toBe(true)
})

it('uploads a file into a new feed, then opens it', async () => {
  acceptFeeds()
  acceptUploads()
  renderDialog()

  const user = await fill('Spring catalogue')
  await user.click(screen.getByLabelText('A file'))
  await user.upload(screen.getByLabelText('File'), new File(['sku\nA-1\n'], 'catalogue.csv', { type: 'text/csv' }))
  expect(screen.getByText(/catalogue\.csv · 8 B/)).toBeInTheDocument()
  await user.click(screen.getByRole('button', { name: 'Add feed' }))

  expect(await screen.findByText('Feed page 7')).toBeInTheDocument()
  expect(created).toEqual([{ name: 'Spring catalogue' }])
})

it('keeps the new feed when its upload fails, and uploads into it again', async () => {
  acceptFeeds(() =>
    HttpResponse.json({ feed: feed({ source: 'upload', host: null, latest_import: null }) }, { status: 201 }),
  )
  acceptUploads((attempt) =>
    attempt === 1
      ? HttpResponse.json({ message: 'This upload is finished or has expired. Start a new one.' }, { status: 410 })
      : new HttpResponse(null, { status: 204 }),
  )
  renderDialog()

  const user = await fill('Spring catalogue')
  await user.click(screen.getByLabelText('A file'))
  await user.upload(screen.getByLabelText('File'), new File(['sku\nA-1\n'], 'catalogue.csv'))
  await user.click(screen.getByRole('button', { name: 'Add feed' }))

  expect(await screen.findByRole('alert')).toHaveTextContent(
    'The feed was created, but its file did not arrive. This upload is finished or has expired. Start a new one.',
  )
  expect(screen.getByLabelText('Name')).toBeDisabled()

  await user.click(screen.getByRole('button', { name: 'Upload again' }))

  expect(await screen.findByText('Feed page 7')).toBeInTheDocument()
  expect(created).toHaveLength(1)
})

it('asks for a file, and refuses an empty one, before creating anything', async () => {
  acceptFeeds()
  renderDialog()

  const user = await fill('Spring catalogue')
  await user.click(screen.getByLabelText('A file'))
  await user.click(screen.getByRole('button', { name: 'Add feed' }))
  expect(await screen.findByText('Choose the file to upload.')).toBeInTheDocument()

  await user.upload(screen.getByLabelText('File'), new File([], 'empty.csv'))
  await user.click(screen.getByRole('button', { name: 'Add feed' }))
  expect(await screen.findByText('This file is empty.')).toBeInTheDocument()
  expect(created).toEqual([])
})
