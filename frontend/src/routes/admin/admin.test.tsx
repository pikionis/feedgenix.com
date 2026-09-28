import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { expect, it } from 'vitest'
import { apiUrl, sessionUser } from '~/test/fixtures'
import { csrfCookie } from '~/test/handlers'
import { renderRoutes } from '~/test/render'
import { server } from '~/test/server'
import AdminHome from './index'
import AdminLayout, { clientLoader } from './layout'

type Status = { enabled: boolean; pending: boolean; recovery_codes_left: number; passed: boolean }

function renderAdmin() {
  return renderRoutes(
    [
      {
        path: '/admin',
        loader: ({ request }) => clientLoader({ request } as Parameters<typeof clientLoader>[0]),
        Component: AdminLayout,
        children: [{ index: true, Component: AdminHome }],
      },
      { path: '/login', Component: () => <p>Sign-in page</p> },
      { path: '/app', Component: () => <p>Workspace home</p> },
    ],
    '/admin',
  )
}

function staffSession(status: { current: Status }) {
  server.use(
    csrfCookie(),
    http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ user: sessionUser({ is_staff: true }) })),
    http.get(apiUrl('/v1/security/two-factor'), () => HttpResponse.json(status.current)),
    http.get(apiUrl('/v1/admin/overview'), () =>
      HttpResponse.json({ workspaces: 12, paid_workspaces: 3, users: 15, verified_users: 14, target_languages: 20 }),
    ),
  )
}

it('sends a visitor without a session to sign in', async () => {
  server.use(
    http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 })),
  )

  renderAdmin()

  expect(await screen.findByText('Sign-in page')).toBeInTheDocument()
})

it('sends a customer back to their workspace', async () => {
  server.use(http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ user: sessionUser() })))

  renderAdmin()

  expect(await screen.findByText('Workspace home')).toBeInTheDocument()
})

it('walks staff through setting up two-factor, shows the recovery codes once, then the platform', async () => {
  const status = { current: { enabled: false, pending: false, recovery_codes_left: 0, passed: false } }
  staffSession(status)
  server.use(
    http.post(apiUrl('/v1/security/two-factor'), () =>
      HttpResponse.json({
        secret: 'JBSWY3DPEHPK3PXP',
        otpauth_url: 'otpauth://totp/x',
        qr_svg_data_url: 'data:image/svg+xml;base64,PHN2Zy8+',
      }),
    ),
    http.post(apiUrl('/v1/security/two-factor/confirm'), async ({ request }) => {
      const { code } = (await request.json()) as { code: string }
      if (code !== '123456') {
        return HttpResponse.json({ message: 'x', errors: { code: ['That code did not match.'] } }, { status: 422 })
      }
      status.current = { enabled: true, pending: false, recovery_codes_left: 8, passed: true }
      return HttpResponse.json({ recovery_codes: ['abcde-fghij', 'klmno-pqrst'] })
    }),
  )
  const user = userEvent.setup()
  renderAdmin()

  await user.click(await screen.findByRole('button', { name: 'Set up two-factor sign-in' }))
  expect(await screen.findByAltText('QR code for your authenticator app')).toBeInTheDocument()
  expect(screen.getByText('JBSWY3DPEHPK3PXP')).toBeInTheDocument()

  await user.type(screen.getByLabelText('Code from the app'), '000000')
  await user.click(screen.getByRole('button', { name: 'Confirm' }))
  expect(await screen.findByText('That code did not match.')).toBeInTheDocument()

  await user.clear(screen.getByLabelText('Code from the app'))
  await user.type(screen.getByLabelText('Code from the app'), '123456')
  await user.click(screen.getByRole('button', { name: 'Confirm' }))
  expect(await screen.findByText('abcde-fghij')).toBeInTheDocument()

  await user.click(screen.getByRole('button', { name: 'I have saved them' }))
  expect(await screen.findByRole('heading', { name: 'Platform' })).toBeInTheDocument()
  expect(await screen.findByText('12')).toBeInTheDocument()
  expect(screen.queryByText('abcde-fghij')).not.toBeInTheDocument()
})

it('asks staff for a code once per session', async () => {
  const status = { current: { enabled: true, pending: false, recovery_codes_left: 8, passed: false } }
  staffSession(status)
  server.use(
    http.post(apiUrl('/v1/security/two-factor/challenge'), async ({ request }) => {
      const { code } = (await request.json()) as { code: string }
      if (code !== '654321') {
        return HttpResponse.json(
          { message: 'x', errors: { code: ['That code did not match, or it was already used.'] } },
          { status: 422 },
        )
      }
      status.current = { ...status.current, passed: true }
      return new HttpResponse(null, { status: 204 })
    }),
  )
  const user = userEvent.setup()
  renderAdmin()

  await user.type(await screen.findByLabelText('Code'), '111111')
  await user.click(screen.getByRole('button', { name: 'Continue' }))
  expect(await screen.findByText('That code did not match, or it was already used.')).toBeInTheDocument()

  await user.clear(screen.getByLabelText('Code'))
  await user.type(screen.getByLabelText('Code'), '654321')
  await user.click(screen.getByRole('button', { name: 'Continue' }))

  expect(await screen.findByRole('heading', { name: 'Platform' })).toBeInTheDocument()
})
