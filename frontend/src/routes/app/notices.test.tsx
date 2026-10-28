import { screen, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { beforeEach, expect, it } from 'vitest'
import type { NoticesResponse, WorkspaceNotice } from '~/lib/api/types'
import { apiUrl } from '~/test/fixtures'
import { csrfCookie, notices } from '~/test/handlers'
import { renderRoutes } from '~/test/render'
import { server } from '~/test/server'
import NoticesPage from './notices'

function notice(overrides: Partial<WorkspaceNotice> = {}): WorkspaceNotice {
  return {
    id: 1,
    kind: 'usage',
    level: 'warn',
    title: "80% of this period's products used",
    body: 'Your workspace has used 400 of the 500 products its Free plan includes until 2027-02-01.',
    action_label: 'See the plans',
    action_path: '/app/settings',
    read: false,
    created_at: '2027-01-20T10:00:00+00:00',
    ...overrides,
  }
}

beforeEach(() => {
  server.use(csrfCookie())
})

function renderNotices() {
  return renderRoutes([{ path: '/app/notices', Component: NoticesPage }], '/app/notices')
}

it('lists what the workspace was told, with the way to act on it, and marks the new ones read', async () => {
  let marked: unknown
  const list: NoticesResponse = {
    notices: [
      notice({ id: 2 }),
      notice({ id: 1, read: true, title: 'Resumed 1 feed', action_label: null, action_path: null }),
    ],
    unread: 1,
  }
  server.use(
    notices(list),
    http.post(apiUrl('/v1/notices/read'), async ({ request }) => {
      marked = await request.json()

      return HttpResponse.json({ notices: list.notices.map((item) => ({ ...item, read: true })), unread: 0 })
    }),
  )
  renderNotices()

  const items = within(await screen.findByRole('list', { name: 'Notices' })).getAllByRole('listitem')
  expect(items).toHaveLength(2)
  expect(items[0]).toHaveTextContent("80% of this period's products used")
  expect(within(items[0]!).getByRole('link', { name: 'See the plans' })).toHaveAttribute('href', '/app/settings')
  expect(within(items[1]!).queryByRole('link')).not.toBeInTheDocument()
  await expect.poll(() => marked).toEqual({ ids: [2] })
})

it('marks nothing when everything was read already', async () => {
  let posted = false
  server.use(
    notices({ notices: [notice({ read: true })], unread: 0 }),
    http.post(apiUrl('/v1/notices/read'), () => {
      posted = true

      return HttpResponse.json({ notices: [], unread: 0 })
    }),
  )
  renderNotices()

  expect(await screen.findByText("80% of this period's products used")).toBeInTheDocument()
  expect(posted).toBe(false)
})

it('says when there is nothing yet', async () => {
  renderNotices()

  expect(await screen.findByText('Nothing yet.')).toBeInTheDocument()
})
