import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { beforeEach, expect, it } from 'vitest'
import type { Feed } from '~/lib/api/types'
import { apiUrl, feed, importSummary, sessionUser, workspace } from '~/test/fixtures'
import { renderRoutes } from '~/test/render'
import { server } from '~/test/server'
import Feeds from './feeds'

const MEMBER = sessionUser({ workspace: { id: 10, name: 'Shop', slug: 'shop', role: 'member', plan: 'free' } })

beforeEach(() => {
  server.use(http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ user: sessionUser() })))
})

function renderFeeds(feeds: Feed[], used = feeds.length) {
  const base = workspace()
  server.use(
    http.get(apiUrl('/v1/feeds'), () => HttpResponse.json({ data: feeds })),
    http.get(apiUrl('/v1/workspace'), () =>
      HttpResponse.json({
        ...base,
        entitlements: { ...base.entitlements, used: { feeds: used, languages: 0, products: 0 } },
      }),
    ),
  )

  return renderRoutes([{ path: '/app/feeds', Component: Feeds }], '/app/feeds')
}

it('invites the owner to add the first feed', async () => {
  renderFeeds([])

  await userEvent.setup().click(await screen.findByRole('button', { name: 'Add your first feed' }))

  expect(screen.getByRole('dialog', { name: 'Add a feed' })).toBeInTheDocument()
})

it('tells members who adds feeds', async () => {
  server.use(http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ user: MEMBER })))
  renderFeeds([])

  expect(await screen.findByText('The owner or an admin of the workspace adds feeds.')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Add/ })).not.toBeInTheDocument()
})

it('lists each feed with where it comes from, its size and how its last import went', async () => {
  renderFeeds([
    feed({ latest_import: importSummary({ row_count: 12_345, column_count: 42 }) }),
    feed({
      id: 8,
      name: 'Spring catalogue',
      source: 'upload',
      host: null,
      latest_import: null,
      profile_import_id: null,
    }),
  ])

  const main = await screen.findByRole('link', { name: /Main shop/ })
  expect(main).toHaveAttribute('href', '/app/feeds/7')
  expect(main).toHaveTextContent('shop.example · CSV · 42 columns')
  expect(main).toHaveTextContent('12,345 products')
  // The file is read; what waits now is the mapping.
  expect(main).toHaveTextContent('Review the mapping')

  const spring = screen.getByRole('link', { name: /Spring catalogue/ })
  expect(spring).toHaveTextContent('Uploaded files')
  expect(spring).toHaveTextContent('No file yet')

  expect(await screen.findByText('2 of 1 feeds on your plan', { exact: false })).toBeInTheDocument()
})
