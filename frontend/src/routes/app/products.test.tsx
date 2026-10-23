import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { beforeEach, expect, it } from 'vitest'
import type { ReviewPage, ReviewText } from '~/lib/api/types'
import {
  apiUrl,
  feed,
  reviewPage,
  reviewProduct,
  reviewText,
  sessionUser,
  SOURCE_HASH,
  translationPlan,
} from '~/test/fixtures'
import { csrfCookie } from '~/test/handlers'
import { renderRoutes } from '~/test/render'
import { server } from '~/test/server'
import ProductsPage from './products'

const FLAGGED = reviewProduct({
  texts: [
    reviewText({
      target: {
        value: '[en] Leather hiking boot 999',
        status: 'qa_failed',
        origin: 'ai',
        stale: false,
        failures: ['numbers'],
        hard: true,
      },
    }),
    reviewText({ field: 'size', label: 'Size', provided: true, source: null, target: null }),
  ],
})

let asked: URLSearchParams[] = []

beforeEach(() => {
  asked = []
  server.use(
    csrfCookie(),
    http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ user: sessionUser() })),
    http.get(apiUrl('/v1/feeds/7'), () => HttpResponse.json({ feed: feed({ mapping_status: 'approved' }) })),
    http.get(apiUrl('/v1/feeds/7/translation-plan'), () =>
      HttpResponse.json({ translation_plan: translationPlan({ target_locales: ['en', 'fr'] }) }),
    ),
  )
})

/** Answers the product list with each page in turn, the last one from then on, and records the queries. */
function servePages(...pages: ReviewPage[]) {
  server.use(
    http.get(apiUrl('/v1/feeds/7/products'), ({ request }) => {
      asked.push(new URL(request.url).searchParams)

      return HttpResponse.json(pages.length > 1 ? pages.shift() : pages[0])
    }),
  )
}

function renderReview(entry = '/app/feeds/7/products') {
  return renderRoutes([{ path: '/app/feeds/:feedId/products', Component: ProductsPage }], entry)
}

it('shows each text with its source, where it stands and what the checks found', async () => {
  servePages(reviewPage([FLAGGED], { counts: { all: 5, review: 2, missing: 1, manual: 0 } }))
  renderReview()

  expect(await screen.findByRole('heading', { name: 'AO-1001' })).toBeInTheDocument()
  expect(screen.getByText('[en] Leather hiking boot 999')).toBeInTheDocument()
  expect(screen.getByText('Wanderschuh aus Leder')).toBeInTheDocument()
  expect(screen.getByText('Not published: a check failed')).toBeInTheDocument()
  expect(screen.getByText('· Numbers or codes differ from the source')).toBeInTheDocument()
  expect(screen.getByText('From the feed')).toBeInTheDocument()
  expect(screen.getByRole('tab', { name: 'To review (2)' })).toBeInTheDocument()
  expect(screen.getByRole('tab', { name: 'All (5)' })).toHaveAttribute('aria-selected', 'true')
  expect(
    within(screen.getByLabelText('Language'))
      .getAllByRole('option')
      .map((option) => option.textContent),
  ).toEqual(['English', 'French'])
  expect(asked[0]?.get('locale')).toBe('en')
})

it('filters, searches, pages and changes language through the address', async () => {
  servePages(reviewPage([FLAGGED], { pages: 3 }))
  renderReview()
  await screen.findByRole('heading', { name: 'AO-1001' })

  await userEvent.click(screen.getByRole('tab', { name: /To review/ }))
  expect(await screen.findByRole('tab', { name: /To review/, selected: true })).toBeInTheDocument()
  await userEvent.type(screen.getByLabelText('SKU or title'), 'zelt')
  await userEvent.click(screen.getByRole('button', { name: 'Search' }))
  await userEvent.click(await screen.findByRole('button', { name: 'Next' }))
  await userEvent.selectOptions(screen.getByLabelText('Language'), 'fr')

  await screen.findByRole('heading', { name: 'AO-1001' })
  const last = asked.at(-1)
  expect(Object.fromEntries(last ?? [])).toEqual({ locale: 'fr', filter: 'review', search: 'zelt', page: '1' })
  expect(asked.some((query) => query.get('page') === '2' && query.get('search') === 'zelt')).toBe(true)
})

it('writes a translation with the source it was made from, and shows it as yours', async () => {
  let sent: unknown
  servePages(reviewPage([FLAGGED]))
  server.use(
    http.put(apiUrl('/v1/products/11/texts/title/en'), async ({ request }) => {
      sent = await request.json()
      const mine: NonNullable<ReviewText['target']> = {
        value: 'Leather hiking boot',
        status: 'ready',
        origin: 'manual',
        stale: false,
        failures: [],
        hard: false,
      }

      return HttpResponse.json({ product: reviewProduct({ texts: [reviewText({ target: mine })] }) })
    }),
  )
  renderReview()

  await userEvent.click(await screen.findByRole('button', { name: 'Edit' }))
  const box = screen.getByLabelText('Title: your translation')
  expect(box).toHaveValue('[en] Leather hiking boot 999')
  await userEvent.clear(box)
  expect(screen.getByRole('button', { name: 'Save' })).toBeDisabled()
  await userEvent.type(box, 'Leather hiking boot')
  await userEvent.click(screen.getByRole('button', { name: 'Save' }))

  expect(await screen.findByText('Yours')).toBeInTheDocument()
  expect(sent).toEqual({ value: 'Leather hiking boot', source_locale: 'de', source_hash: SOURCE_HASH })
  expect(screen.queryByLabelText('Title: your translation')).not.toBeInTheDocument()
})

it('approves a flagged translation, and says so when the source changed meanwhile', async () => {
  let approvals = 0
  servePages(reviewPage([FLAGGED]))
  server.use(
    http.post(apiUrl('/v1/products/11/texts/title/en/approve'), async ({ request }) => {
      approvals++
      expect(await request.json()).toEqual({ source_hash: SOURCE_HASH })

      return HttpResponse.json({ error: 'source_changed', message: 'Changed.' }, { status: 409 })
    }),
  )
  renderReview()

  await userEvent.click(await screen.findByRole('button', { name: 'Approve as it is' }))

  expect(await screen.findByRole('alert')).toHaveTextContent('This text changed in the feed meanwhile')
  expect(approvals).toBe(1)
  // Reloaded: the list was asked for again.
  await expect.poll(() => asked.length).toBeGreaterThan(1)
})

it('hands a text back to the AI', async () => {
  let reset = false
  servePages(reviewPage([FLAGGED]))
  server.use(
    http.delete(apiUrl('/v1/products/11/texts/title/en'), () => {
      reset = true
      const handedBack = reviewProduct({ texts: [reviewText({ target: null })] })
      servePages(reviewPage([handedBack]))

      return HttpResponse.json({ product: handedBack })
    }),
  )
  renderReview()

  await userEvent.click(await screen.findByRole('button', { name: 'Let the AI translate it again' }))

  expect(await screen.findByText('Not translated yet')).toBeInTheDocument()
  expect(reset).toBe(true)
  expect(screen.getByRole('button', { name: 'Translate it yourself' })).toBeInTheDocument()
})

it('offers no change of the feed’s own text, nor of a clean translation’s approval', async () => {
  servePages(reviewPage([reviewProduct({ texts: [reviewText(), FLAGGED.texts[1]!] })]))
  renderReview()

  expect(await screen.findByText('From the feed')).toBeInTheDocument()
  expect(screen.getAllByRole('button', { name: 'Edit' })).toHaveLength(1)
  expect(screen.queryByRole('button', { name: 'Approve as it is' })).not.toBeInTheDocument()
})

it('lets members read, never change', async () => {
  const user = sessionUser()
  server.use(
    http.get(apiUrl('/v1/auth/user'), () =>
      HttpResponse.json({ user: { ...user, workspace: user.workspace && { ...user.workspace, role: 'member' } } }),
    ),
  )
  servePages(reviewPage([FLAGGED]))
  renderReview()

  expect(await screen.findByText('[en] Leather hiking boot 999')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Edit' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Approve as it is' })).not.toBeInTheDocument()
})

it('says there is nothing to review before a language is chosen', async () => {
  server.use(
    http.get(apiUrl('/v1/feeds/7/translation-plan'), () =>
      HttpResponse.json({ translation_plan: translationPlan({ target_locales: [] }) }),
    ),
  )
  servePages(reviewPage())
  renderReview()

  expect(await screen.findByText('Nothing to review yet')).toBeInTheDocument()
  expect(asked).toEqual([])
})
