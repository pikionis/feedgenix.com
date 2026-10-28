import { screen, within } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { expect, it } from 'vitest'
import { apiUrl, sessionUser, workspace } from '~/test/fixtures'
import { notices } from '~/test/handlers'
import { renderRoutes } from '~/test/render'
import { server } from '~/test/server'
import AppLayout, { clientLoader } from './layout'

function renderApp(entry: string) {
  return renderRoutes(
    [
      {
        path: '/app',
        // The real loader: it decides whether the person may see the workspace.
        loader: ({ request }) => clientLoader({ request } as Parameters<typeof clientLoader>[0]),
        Component: AppLayout,
        children: [
          { index: true, Component: () => <p>Overview page</p> },
          { path: 'settings', Component: () => <p>Settings</p> },
        ],
      },
      { path: '/login', Component: () => <p>Sign-in page</p> },
    ],
    entry,
  )
}

it('sends a visitor without a session to sign in', async () => {
  server.use(
    http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 })),
  )

  renderApp('/app/settings')

  expect(await screen.findByText('Sign-in page')).toBeInTheDocument()
})

it('shows the workspace to a signed-in member', async () => {
  server.use(
    http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ user: sessionUser() })),
    http.get(apiUrl('/v1/workspace'), () => HttpResponse.json(workspace())),
  )

  renderApp('/app')

  expect(await screen.findByText('Overview page')).toBeInTheDocument()
  expect(screen.getByText("Alice Martin's workspace")).toBeInTheDocument()
  expect(screen.queryByText(/Verify your address/)).not.toBeInTheDocument()
})

it('has one navigation and one sign-out, with the plan and its usage', async () => {
  server.use(
    http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ user: sessionUser() })),
    http.get(apiUrl('/v1/workspace'), () => HttpResponse.json(workspace())),
  )

  renderApp('/app')

  const nav = await screen.findByRole('navigation', { name: 'Workspace' })
  expect(
    within(nav)
      .getAllByRole('link')
      .map((link) => link.textContent),
  ).toEqual(['Overview', 'Feeds', 'Notices', 'Settings'])
  expect(screen.getAllByRole('button', { name: 'Sign out' })).toHaveLength(1)
  expect(screen.getByText('Free plan')).toBeInTheDocument()
  // The fixture's Free plan: 0 of 1 feed, 0 of 1 language.
  const feedsUsed = await screen.findByText('Feeds', { selector: 'dt' })
  expect(feedsUsed.nextElementSibling).toHaveTextContent('0 of 1')
})

it('asks an unverified member to verify the address', async () => {
  server.use(
    http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ user: sessionUser({ email_verified: false }) })),
    http.get(apiUrl('/v1/workspace'), () => HttpResponse.json(workspace())),
  )

  renderApp('/app')

  expect(await screen.findByText(/Verify your address/)).toBeInTheDocument()
})

it('shows how many notices are unread next to Notices', async () => {
  server.use(
    http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ user: sessionUser() })),
    http.get(apiUrl('/v1/workspace'), () => HttpResponse.json(workspace())),
    notices({ notices: [], unread: 3 }),
  )

  renderApp('/app')

  const nav = await screen.findByRole('navigation', { name: 'Workspace' })
  const link = await within(nav).findByRole('link', { name: /^Notices/ })
  await expect.poll(() => link.textContent).toBe('Notices 3 unread')
  expect(link).toHaveAttribute('href', '/app/notices')
})
