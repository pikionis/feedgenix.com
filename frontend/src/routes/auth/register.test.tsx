import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { expect, it } from 'vitest'
import { apiUrl, sessionUser } from '~/test/fixtures'
import { csrfCookie } from '~/test/handlers'
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
