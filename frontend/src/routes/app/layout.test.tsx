import { screen } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { expect, it } from 'vitest'
import { apiUrl, sessionUser } from '~/test/fixtures'
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
  server.use(http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ user: sessionUser() })))

  renderApp('/app')

  expect(await screen.findByText('Overview page')).toBeInTheDocument()
  expect(screen.getByText("Alice Martin's workspace")).toBeInTheDocument()
  expect(screen.queryByText(/Verify your address/)).not.toBeInTheDocument()
})

it('asks an unverified member to verify the address', async () => {
  server.use(
    http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ user: sessionUser({ email_verified: false }) })),
  )

  renderApp('/app')

  expect(await screen.findByText(/Verify your address/)).toBeInTheDocument()
})
