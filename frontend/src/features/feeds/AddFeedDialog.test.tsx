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

it('sends no login unless one was chosen, even after typing one and changing their mind', async () => {
  acceptFeeds()
  renderDialog()

  const user = await fill('Main shop', 'https://shop.example/feed.xml')
  await user.click(screen.getByLabelText('Password'))
  await user.type(screen.getByLabelText('Username'), 'someone')
  await user.click(screen.getByRole('radio', { name: 'No' }))
  await user.click(screen.getByRole('button', { name: 'Add feed' }))

  expect(await screen.findByText('Feed page 7')).toBeInTheDocument()
  expect(created).toEqual([{ name: 'Main shop', url: 'https://shop.example/feed.xml' }])
})

it('adds a feed with a username and password, exactly as typed', async () => {
  acceptFeeds()
  renderDialog()

  const user = await fill('Main shop', 'https://shop.example/feed.xml')
  await user.click(screen.getByRole('radio', { name: 'Password' }))
  await user.type(screen.getByLabelText('Username'), ' someone ')
  await user.type(screen.getByLabelText('Password', { selector: 'input[type=password]' }), '  pass phrase  ')
  await user.click(screen.getByRole('button', { name: 'Add feed' }))

  expect(await screen.findByText('Feed page 7')).toBeInTheDocument()
  expect(created).toEqual([
    {
      name: 'Main shop',
      url: 'https://shop.example/feed.xml',
      auth: { type: 'password', username: ' someone ', password: '  pass phrase  ' },
    },
  ])
})

it('adds a feed with a header login', async () => {
  acceptFeeds()
  renderDialog()

  const user = await fill('Main shop', 'https://shop.example/feed.xml')
  await user.click(screen.getByRole('radio', { name: 'Header' }))
  await user.type(screen.getByLabelText('Header name'), 'X-Api-Key')
  await user.type(screen.getByLabelText('Header value'), 'key-123')
  await user.click(screen.getByRole('button', { name: 'Add feed' }))

  expect(await screen.findByText('Feed page 7')).toBeInTheDocument()
  expect(created).toEqual([
    {
      name: 'Main shop',
      url: 'https://shop.example/feed.xml',
      auth: { type: 'header', name: 'X-Api-Key', value: 'key-123' },
    },
  ])
})

it('asks for what the login is missing, and refuses plain http, before sending anything', async () => {
  acceptFeeds()
  renderDialog()

  const user = await fill('Main shop', 'http://shop.example/feed.xml')
  await user.click(screen.getByRole('radio', { name: 'Password' }))
  await user.click(screen.getByRole('button', { name: 'Add feed' }))

  expect(await screen.findByText('Enter the username.')).toBeInTheDocument()
  expect(screen.getByText('Enter the password.')).toBeInTheDocument()
  expect(screen.getByText('A login is only ever sent over https. Use an https:// address.')).toBeInTheDocument()
  expect(created).toEqual([])
})

it('keeps the browser from filling the login with what it saved for FeedGenix', async () => {
  renderDialog()

  const user = userEvent.setup()
  await user.click(await screen.findByRole('radio', { name: 'Password' }))

  expect(screen.getByLabelText('Username')).toHaveAttribute('autocomplete', 'off')
  expect(screen.getByLabelText('Password', { selector: 'input[type=password]' })).toHaveAttribute(
    'autocomplete',
    'new-password',
  )
})

it('checks the address before sending anything', async () => {
  acceptFeeds()
  renderDialog()

  const user = await fill('Main shop', 'shop.example/feed.xml')
  await user.click(screen.getByRole('button', { name: 'Add feed' }))

  expect(await screen.findByText('Enter the full address, starting with https:// or ftp://.')).toBeInTheDocument()
  expect(created).toEqual([])
})

it('adds a feed from an FTP server, and warns that plain FTP sends the password unencrypted', async () => {
  acceptFeeds()
  renderDialog()

  const user = await fill('FTP shop', 'ftp://files.example/feed.csv')
  expect(screen.queryByText(/sends the password unencrypted/)).not.toBeInTheDocument()

  await user.click(screen.getByRole('radio', { name: 'Password' }))
  expect(await screen.findByText(/sends the password unencrypted/)).toBeInTheDocument()

  await user.type(screen.getByLabelText('Username'), 'someone')
  await user.type(screen.getByLabelText('Password', { selector: 'input[type=password]' }), 'secret')
  await user.click(screen.getByRole('button', { name: 'Add feed' }))

  expect(await screen.findByText('Feed page 7')).toBeInTheDocument()
  expect(created).toEqual([
    {
      name: 'FTP shop',
      url: 'ftp://files.example/feed.csv',
      auth: { type: 'password', username: 'someone', password: 'secret' },
    },
  ])
})

it('does not warn on ftps, and takes an address without a login', async () => {
  acceptFeeds()
  renderDialog()

  const user = await fill('FTPS shop', 'ftps://files.example/feed.csv')
  await user.click(screen.getByRole('radio', { name: 'Password' }))
  expect(screen.queryByText(/sends the password unencrypted/)).not.toBeInTheDocument()

  await user.click(screen.getByRole('radio', { name: 'No' }))
  await user.click(screen.getByRole('button', { name: 'Add feed' }))

  expect(await screen.findByText('Feed page 7')).toBeInTheDocument()
  expect(created).toEqual([{ name: 'FTPS shop', url: 'ftps://files.example/feed.csv' }])
})

it('refuses a header login on an FTP address before sending anything', async () => {
  acceptFeeds()
  renderDialog()

  const user = await fill('FTP shop', 'ftp://files.example/feed.csv')
  await user.click(screen.getByRole('radio', { name: 'Header' }))
  await user.type(screen.getByLabelText('Header name'), 'X-Key')
  await user.type(screen.getByLabelText('Header value'), 'v')
  await user.click(screen.getByRole('button', { name: 'Add feed' }))

  expect(await screen.findByText(/A header only works on an https:\/\/ address/)).toBeInTheDocument()
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
  expect(created).toEqual([{ name: 'Spring catalogue', upload_size: 8 }])
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

it('says how large a file the plan reads', async () => {
  renderDialog()

  await userEvent.setup().click(await screen.findByLabelText('A file'))

  expect(await screen.findByText(/Up to 2 GB uncompressed on your plan\./)).toBeInTheDocument()
})

it('lets the upgrade dialog explain a file larger than the plan, before any feed exists', async () => {
  const message = 'This file is 3 GB; your Free plan reads feeds up to 2 GB. Pro reads feeds up to 10 GB.'
  acceptFeeds(() =>
    HttpResponse.json(
      {
        error: 'plan_limit',
        limit: 'feed_bytes',
        allowed: 2,
        used: null,
        requested: 3,
        plan: 'free',
        upgrade_to: 'pro',
        message,
      },
      { status: 402 },
    ),
  )
  const dialog = renderDialog()

  const user = await fill('Huge catalogue')
  await user.click(screen.getByLabelText('A file'))
  await user.upload(screen.getByLabelText('File'), new File(['sku\nA-1\n'], 'huge.csv'))
  await user.click(screen.getByRole('button', { name: 'Add feed' }))

  expect(await screen.findByRole('dialog')).toHaveTextContent(message)
  expect(dialog.closed()).toBe(true)
  expect(created).toEqual([{ name: 'Huge catalogue', upload_size: 8 }])
})
