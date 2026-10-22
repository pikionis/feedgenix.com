import { screen, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { expect, it } from 'vitest'
import { apiUrl, plansResponse } from '~/test/fixtures'
import { renderRoutes } from '~/test/render'
import { server } from '~/test/server'
import Home from './home'

function renderHome() {
  return renderRoutes([{ path: '/', Component: Home }], '/')
}

/** Every text node, joined by spaces: textContent glues neighbours ("G2" + "alpine" → "G2alpine"). */
function pageText(root: HTMLElement): string {
  const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT)
  const parts: string[] = []
  for (let node = walker.nextNode(); node !== null; node = walker.nextNode()) parts.push(node.textContent ?? '')

  return parts.join(' ')
}

it('promises the golden rule and sends visitors to sign up or sign in', async () => {
  server.use(http.get(apiUrl('/plans'), () => HttpResponse.json(plansResponse)))
  renderHome()

  expect(await screen.findByRole('heading', { level: 1 })).toHaveTextContent('every language your buyers shop in')
  expect(screen.getByText(/pass through untouched/i)).toBeInTheDocument()
  const nav = screen.getByRole('navigation', { name: 'Main' })
  expect(within(nav).getByRole('link', { name: 'Start free' })).toHaveAttribute('href', '/register')
  expect(within(nav).getByRole('link', { name: 'Sign in' })).toHaveAttribute('href', '/login')
  expect(screen.getAllByRole('heading', { level: 3 }).map((h) => h.textContent)).toEqual(
    expect.arrayContaining(['Connect a feed', 'Review the mapping', 'Translate and check', 'Publish a feed URL']),
  )
})

it('shows the live plans from the API, with the recommended one marked', async () => {
  server.use(http.get(apiUrl('/plans'), () => HttpResponse.json(plansResponse)))
  renderHome()

  const pro = await screen.findByRole('article', { name: 'Pro plan' })
  expect(pro).toHaveTextContent('€40/mo')
  expect(pro).toHaveTextContent('or €30/mo billed yearly, €360 a year')
  expect(pro).toHaveTextContent('Most popular')
  expect(within(pro).getByRole('link', { name: 'Start free, upgrade later' })).toHaveAttribute('href', '/register')
  expect(screen.getByRole('article', { name: 'Free plan' })).toHaveTextContent('Free for good')
  expect(screen.getByRole('article', { name: 'Ultra plan' })).toHaveTextContent('€100/mo')
  expect(screen.getByRole('article', { name: 'Custom plan' })).toHaveTextContent('€180/mo')
  expect(screen.getAllByText('Most popular')).toHaveLength(1)
})

it('says so when the plans cannot be loaded, and the rest of the page still works', async () => {
  server.use(http.get(apiUrl('/plans'), () => new HttpResponse(null, { status: 503 })))
  renderHome()

  expect(await screen.findByRole('alert')).toHaveTextContent('The plans could not be loaded')
  expect(screen.getByRole('heading', { level: 1 })).toBeInTheDocument()
})

it('claims nothing that is not true and never shows a cost or the markup', async () => {
  server.use(http.get(apiUrl('/plans'), () => HttpResponse.json(plansResponse)))
  const { container } = renderHome()
  await screen.findByRole('article', { name: 'Pro plan' })

  const text = pageText(container)
  // The April design's placeholders: a review score, a certification, a company register entry.
  for (const claim of [/\bG2\b/, /SOC ?2/i, /\bAG\b/, /CH-\d/, /Zürich/]) expect(text).not.toMatch(claim)
  // The pricing rule: no cost per row, no provider price, no markup, anywhere.
  for (const leak of [/¢/, /markup/i, /provider (cost|price)/i, /per row/i]) expect(text).not.toMatch(leak)
})
