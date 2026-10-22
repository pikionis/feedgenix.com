import { act, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { describe, expect, it } from 'vitest'
import { apiUrl, sessionUser } from '~/test/fixtures'
import { authProvidersQuery } from '~/features/auth/google'
import { authProviders, csrfCookie } from '~/test/handlers'
import { renderRoutes } from '~/test/render'
import { server } from '~/test/server'
import Login from './login'

function renderLogin(entry = '/login') {
  return renderRoutes(
    [
      { path: '/login', Component: Login },
      { path: '/app', Component: () => <p>Workspace home</p> },
      { path: '/app/settings', Component: () => <p>Settings page</p> },
    ],
    entry,
  )
}

async function signIn(email = 'alice@example.com', password = 'correct horse') {
  const user = userEvent.setup()
  await user.type(await screen.findByLabelText('Email'), email)
  await user.type(screen.getByLabelText('Password'), password)
  await user.click(screen.getByRole('button', { name: 'Sign in' }))
}

it('asks for both fields before calling the API', async () => {
  renderLogin()

  await userEvent.setup().click(await screen.findByRole('button', { name: 'Sign in' }))

  // No API handler is registered: a request would have failed the test.
  expect(await screen.findByText('Enter a valid email address.')).toBeInTheDocument()
  expect(screen.getByText('Enter your password.')).toBeInTheDocument()
})

it('shows the server’s answer under the email field', async () => {
  server.use(
    csrfCookie(),
    http.post(apiUrl('/auth/login'), () =>
      HttpResponse.json(
        {
          message: 'These credentials do not match our records.',
          errors: { email: ['These credentials do not match our records.'] },
        },
        { status: 422 },
      ),
    ),
  )
  renderLogin()

  await signIn()

  expect(await screen.findByText('These credentials do not match our records.')).toBeInTheDocument()
  expect(screen.getByLabelText('Email')).toHaveAttribute('aria-invalid', 'true')
})

it('explains a lock-out after too many attempts', async () => {
  server.use(
    csrfCookie(),
    http.post(apiUrl('/auth/login'), () => new HttpResponse(null, { status: 429 })),
  )
  renderLogin()

  await signIn()

  expect(await screen.findByRole('alert')).toHaveTextContent('Too many attempts')
})

it('signs in and returns to the page that was asked for', async () => {
  server.use(
    csrfCookie(),
    http.post(apiUrl('/auth/login'), () => HttpResponse.json({ user: sessionUser() })),
  )
  renderLogin('/login?next=%2Fapp%2Fsettings')

  await signIn()

  expect(await screen.findByText('Settings page')).toBeInTheDocument()
})

it('never follows a next link outside the app', async () => {
  server.use(
    csrfCookie(),
    http.post(apiUrl('/auth/login'), () => HttpResponse.json({ user: sessionUser() })),
  )
  renderLogin('/login?next=%2F%2Fevil.example%2Fapp')

  await signIn()

  expect(await screen.findByText('Workspace home')).toBeInTheDocument()
})

it('explains why a Google sign-in came back', async () => {
  renderLogin('/login?error=conflict')

  expect(await screen.findByRole('alert')).toHaveTextContent('already linked to a different Google account')
})

describe('Google sign-in', () => {
  it('offers Google when the server has its credentials', async () => {
    server.use(authProviders(true))
    renderLogin()

    expect(await screen.findByRole('link', { name: 'Continue with Google' })).toHaveAttribute(
      'href',
      '/auth/google/redirect',
    )
  })

  it('shows no Google button (and no "or") when the server has no Google credentials', async () => {
    const { client } = renderLogin()

    await waitFor(() => expect(client.getQueryState(authProvidersQuery.queryKey)?.status).toBe('success'))
    await act(async () => {})
    expect(screen.queryByRole('link', { name: 'Continue with Google' })).not.toBeInTheDocument()
    expect(screen.queryByText('or')).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Sign in' })).toBeInTheDocument()
  })

  it('says why when a Google sign-in was not possible', async () => {
    renderLogin('/login?error=google_unavailable')

    expect(await screen.findByRole('alert')).toHaveTextContent('Google sign-in is not available yet')
  })
})
