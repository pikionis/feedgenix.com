import { act, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { describe, expect, it } from 'vitest'
import { apiUrl, sessionUser } from '~/test/fixtures'
import { authOptionsQuery } from '~/features/auth/options'
import { authOptions, csrfCookie } from '~/test/handlers'
import { renderRoutes } from '~/test/render'
import { server } from '~/test/server'
import Register from './register'

function renderRegister(entry = '/register') {
  return renderRoutes(
    [
      { path: '/register', Component: Register },
      { path: '/app', Component: () => <p>Workspace home</p> },
    ],
    entry,
  )
}

async function fillAndSubmit() {
  const user = userEvent.setup()
  await user.type(await screen.findByLabelText('Name'), 'Alice Martin')
  await user.type(screen.getByLabelText('Email'), 'alice@example.com')
  await user.type(screen.getByLabelText('Password'), 'correct horse battery')
  await user.type(screen.getByLabelText('Repeat the password'), 'correct horse battery')
  await user.click(screen.getByRole('button', { name: 'Create account' }))
}

it('fills in the invite code from the invite link and sends it', async () => {
  let sent: unknown = null
  server.use(
    csrfCookie(),
    http.post(apiUrl('/auth/register'), async ({ request }) => {
      sent = await request.json()
      return HttpResponse.json({ user: sessionUser({ email_verified: false }) }, { status: 201 })
    }),
  )
  renderRegister('/register?invite=fg-abc123')

  expect(await screen.findByLabelText('Invite code')).toHaveValue('fg-abc123')
  await fillAndSubmit()

  expect(await screen.findByText('Workspace home')).toBeInTheDocument()
  expect(sent).toMatchObject({ email: 'alice@example.com', invite_code: 'fg-abc123' })
})

it('shows an invalid invite on the invite field', async () => {
  server.use(
    csrfCookie(),
    http.post(apiUrl('/auth/register'), () =>
      HttpResponse.json(
        { message: 'This invite code is not valid.', errors: { invite_code: ['This invite code is not valid.'] } },
        { status: 422 },
      ),
    ),
  )
  renderRegister()

  await fillAndSubmit()

  expect(await screen.findByText('This invite code is not valid.')).toBeInTheDocument()
  expect(screen.getByLabelText('Invite code')).toHaveAttribute('aria-invalid', 'true')
})

it('checks that the two passwords match before calling the API', async () => {
  renderRegister()
  const user = userEvent.setup()

  await user.type(await screen.findByLabelText('Password'), 'correct horse battery')
  await user.type(screen.getByLabelText('Repeat the password'), 'different')
  await user.click(screen.getByRole('button', { name: 'Create account' }))

  expect(await screen.findByText('The passwords do not match.')).toBeInTheDocument()
})

it('says so when sign-up is closed', async () => {
  server.use(
    csrfCookie(),
    http.post(apiUrl('/auth/register'), () =>
      HttpResponse.json({ message: 'Sign-up is closed at the moment.' }, { status: 403 }),
    ),
  )
  renderRegister()

  await fillAndSubmit()

  expect(await screen.findByRole('alert')).toHaveTextContent('Sign-up is closed at the moment.')
})

describe('Google sign-up', () => {
  it('carries the invite into the Google link', async () => {
    server.use(authOptions({ google: true }))
    renderRegister('/register?invite=fg-abc123')

    expect(await screen.findByRole('link', { name: 'Continue with Google' })).toHaveAttribute(
      'href',
      '/auth/google/redirect?invite=fg-abc123',
    )
  })

  it('offers only email and password when the server has no Google credentials', async () => {
    const { client } = renderRegister('/register?invite=fg-abc123')

    await waitFor(() => expect(client.getQueryState(authOptionsQuery.queryKey)?.status).toBe('success'))
    await act(async () => {})
    expect(screen.queryByRole('link', { name: 'Continue with Google' })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Create account' })).toBeInTheDocument()
  })

  it('keeps the invite code and says why after an unavailable Google sign-up', async () => {
    renderRegister('/register?invite=fg-abc123&error=google_unavailable')

    expect(await screen.findByRole('alert')).toHaveTextContent('Use your email and a password')
    expect(await screen.findByLabelText('Invite code')).toHaveValue('fg-abc123')
  })
})

describe('who may sign up', () => {
  it('asks for an invite code only during an invite-only beta', async () => {
    renderRegister('/register?invite=fg-abc123')

    expect(await screen.findByLabelText('Invite code')).toHaveValue('fg-abc123')
  })

  it('asks for no invite code once sign-up is open, and sends none', async () => {
    let sent: { invite_code?: unknown } | null = null
    server.use(
      authOptions({ registration: 'open' }),
      csrfCookie(),
      http.post(apiUrl('/auth/register'), async ({ request }) => {
        sent = (await request.json()) as { invite_code?: unknown }
        return HttpResponse.json({ user: sessionUser({ email_verified: false }) }, { status: 201 })
      }),
    )
    const { client } = renderRegister('/register?invite=fg-old-link')

    await waitFor(() => expect(client.getQueryState(authOptionsQuery.queryKey)?.status).toBe('success'))
    await act(async () => {})
    expect(screen.queryByLabelText('Invite code')).not.toBeInTheDocument()
    await fillAndSubmit()

    expect(await screen.findByText('Workspace home')).toBeInTheDocument()
    expect(sent).toMatchObject({ invite_code: null })
  })

  it('says sign-up is closed instead of showing a form nobody can submit', async () => {
    server.use(authOptions({ registration: 'closed' }))
    renderRegister()

    expect(await screen.findByRole('status')).toHaveTextContent('Sign-up is closed at the moment')
    expect(screen.queryByRole('button', { name: 'Create account' })).not.toBeInTheDocument()
  })

  it('shows the invite field when the options cannot be loaded, so invitees are never locked out', async () => {
    server.use(http.get(apiUrl('/auth/options'), () => new HttpResponse(null, { status: 503 })))
    renderRegister('/register?invite=fg-abc123')

    expect(await screen.findByLabelText('Invite code')).toHaveValue('fg-abc123')
  })
})
